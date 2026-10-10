<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ExpenseRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ExpenseItemRepository;
use App\Repositories\GroupRepository;
use App\Services\CurrencyService;
use App\Utils\CsvSanitizer;
use App\Utils\Money;
use InvalidArgumentException;

/**
 * Domain service for expense logging and split calculation.
 */
class ExpenseService
{
    private ExpenseRepository $expenseRepo;
    private MemberRepository $memberRepo;
    private ExpenseItemRepository $itemRepo;
    private GroupRepository $groupRepo;

    public function __construct(
        ?ExpenseRepository $expenseRepo = null,
        ?MemberRepository $memberRepo = null,
        ?ExpenseItemRepository $itemRepo = null,
        ?GroupRepository $groupRepo = null
    ) {
        $this->expenseRepo = $expenseRepo ?? new ExpenseRepository();
        $this->memberRepo = $memberRepo ?? new MemberRepository();
        $this->itemRepo = $itemRepo ?? new ExpenseItemRepository();
        $this->groupRepo = $groupRepo ?? new GroupRepository();
    }

    /**
     * Process, validate, calculate splits, and persist a new group expense.
     *
     * @param int $groupId
     * @param array<string, mixed> $payload
     * @return int Created Expense ID.
     * @throws InvalidArgumentException
     */
    public function createExpense(int $groupId, array $payload, ?string $idempotencyKey = null, ?bool &$isDuplicate = null): int
    {
        // 1. Basic Field Validations
        if (empty($payload['title']) || !is_string($payload['title']) || trim($payload['title']) === '') {
            throw new InvalidArgumentException("Expense title cannot be empty.", 422);
        }
        $title = trim(strip_tags((string) $payload['title']));

        $splitType = strtoupper((string) ($payload['split_type'] ?? 'EQUAL'));
        $validSplitTypes = ['EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES', 'ITEMIZED'];
        if (!in_array($splitType, $validSplitTypes, true)) {
            throw new InvalidArgumentException("Invalid split type '{$splitType}'. Supported: EQUAL, EXACT, PERCENTAGE, SHARES, ITEMIZED.", 422);
        }

        // Multi-Currency & Cross-Exchange Rate Resolution
        $group = $this->groupRepo->findById($groupId);
        $baseCurrency = $group['currency_code'] ?? 'INR';

        $reqCurrency = null;
        if (!empty($payload['original_currency_code'])) {
            $reqCurrency = (string) $payload['original_currency_code'];
        } elseif (!empty($payload['original_currency'])) {
            $reqCurrency = (string) $payload['original_currency'];
        } elseif (!empty($payload['currency'])) {
            $reqCurrency = (string) $payload['currency'];
        }

        $originalCurrencyCode = null;
        $originalAmountCents = null;
        $exchangeRate = null;

        $hasExplicitTotal = isset($payload['total_amount_cents']) || isset($payload['amount_cents']);
        $totalCents = 0;
        if (isset($payload['total_amount_cents'])) {
            $totalCents = (int) $payload['total_amount_cents'];
        } elseif (isset($payload['amount_cents'])) {
            $totalCents = (int) $payload['amount_cents'];
        } elseif (isset($payload['amount'])) {
            $totalCents = Money::toCents($payload['amount']);
        } elseif ($splitType === 'ITEMIZED' && !empty($payload['items'])) {
            $itemSubtotal = array_sum(array_map(function ($i) {
                return (int) ($i['amount_cents'] ?? Money::toCents($i['amount'] ?? 0));
            }, $payload['items']));
            $totalCents = $itemSubtotal + (int) ($payload['tax_cents'] ?? 0) + (int) ($payload['tip_cents'] ?? 0) - (int) ($payload['discount_cents'] ?? 0);
        }

        if ($reqCurrency && strtoupper(trim($reqCurrency)) !== strtoupper(trim($baseCurrency))) {
            $reqCode = strtoupper(trim($reqCurrency));
            if (!CurrencyService::isSupported($reqCode)) {
                throw new InvalidArgumentException("Unsupported currency code '{$reqCode}'.", 422);
            }

            $originalCurrencyCode = $reqCode;

            if (isset($payload['original_amount_cents']) && (int) $payload['original_amount_cents'] > 0) {
                $originalAmountCents = (int) $payload['original_amount_cents'];
            } elseif (isset($payload['original_amount']) && $payload['original_amount'] !== '') {
                $originalAmountCents = Money::toCents($payload['original_amount']);
            } elseif (isset($payload['amount'])) {
                $originalAmountCents = Money::toCents($payload['amount']);
            } elseif ($totalCents > 0) {
                $originalAmountCents = $totalCents;
            }

            if (isset($payload['exchange_rate']) && (float) $payload['exchange_rate'] > 0) {
                $exchangeRate = (float) $payload['exchange_rate'];
            } else {
                $exchangeRate = CurrencyService::getExchangeRate($originalCurrencyCode, $baseCurrency);
            }

            if (!$hasExplicitTotal && $originalAmountCents !== null) {
                $totalCents = (int) round($originalAmountCents * $exchangeRate);
            }
        }

        if ($totalCents <= 0 && $originalAmountCents !== null && $exchangeRate !== null) {
            $totalCents = (int) round($originalAmountCents * $exchangeRate);
        }

        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Expense amount must be greater than zero.", 422);
        }

        $expenseDate = isset($payload['expense_date']) && !empty($payload['expense_date'])
            ? (string) $payload['expense_date']
            : date('Y-m-d');

        $categoryId = isset($payload['category_id']) && !empty($payload['category_id'])
            ? (int) $payload['category_id']
            : null;

        // 2. Fetch Group Active Members for Membership Validation
        $groupMembers = $this->memberRepo->findByGroupId($groupId);
        $validMemberIds = array_map('intval', array_column($groupMembers, 'id'));

        if (empty($validMemberIds)) {
            throw new InvalidArgumentException("Group has no members.", 422);
        }

        $createdByMemberId = isset($payload['created_by_member_id'])
            ? (int) $payload['created_by_member_id']
            : (int) $validMemberIds[0];

        if (!in_array($createdByMemberId, $validMemberIds, true)) {
            throw new InvalidArgumentException("Creator member does not belong to this group.", 422);
        }

        // 3. Process & Validate Payers
        $payers = $this->resolvePayers($payload, $totalCents, $validMemberIds, $createdByMemberId);

        // 4. Calculate & Validate Splits
        $splits = $this->resolveSplits($splitType, $payload, $totalCents, $validMemberIds);

        $taxCents = (int) ($payload['tax_cents'] ?? 0);
        $tipCents = (int) ($payload['tip_cents'] ?? 0);
        $discountCents = (int) ($payload['discount_cents'] ?? 0);
        $notes = isset($payload['notes']) && is_string($payload['notes']) ? trim($payload['notes']) : null;

        // 5. Atomic Persistence via Repository
        $expenseId = $this->expenseRepo->createExpense(
            $groupId,
            $title,
            $totalCents,
            $splitType,
            $expenseDate,
            $createdByMemberId,
            $payers,
            $splits,
            $categoryId,
            $taxCents,
            $tipCents,
            $discountCents,
            $notes,
            $originalCurrencyCode,
            $originalAmountCents,
            $exchangeRate,
            $idempotencyKey,
            $isDuplicate
        );

        if ($isDuplicate) {
            return $expenseId;
        }

        if ($splitType === 'ITEMIZED' && !empty($payload['items']) && is_array($payload['items'])) {
            $this->itemRepo->saveItems($expenseId, $payload['items']);
        }

        // Process optional receipt attachment
        $receiptBase64 = $payload['receipt_base64'] ?? $payload['data_base64'] ?? null;
        if (!empty($receiptBase64)) {
            $receiptFileName = (string) ($payload['receipt_file_name'] ?? $payload['file_name'] ?? 'receipt.jpg');
            $receiptService = new ReceiptService();
            $receiptService->uploadReceiptBase64($groupId, $expenseId, $receiptFileName, (string) $receiptBase64, $defaultPayerId ?? null);
        }

        return $expenseId;
    }

    /**
     * Atomically update an existing expense and its allocations.
     *
     * @param int $groupId
     * @param int $expenseId
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function updateExpense(int $groupId, int $expenseId, array $payload): array
    {
        $existing = $this->expenseRepo->findDetailsById($expenseId);
        if (!$existing || $existing['group_id'] !== $groupId || $existing['is_deleted']) {
            throw new InvalidArgumentException("Expense not found or does not belong to group.", 404);
        }

        if (empty($payload['title']) || !is_string($payload['title']) || trim($payload['title']) === '') {
            throw new InvalidArgumentException("Expense title cannot be empty.", 422);
        }
        $title = trim(strip_tags((string) $payload['title']));

        $group = $this->groupRepo->findById($groupId);
        $baseCurrency = $group['currency_code'] ?? 'INR';

        $totalCents = isset($payload['total_amount_cents'])
            ? (int) $payload['total_amount_cents']
            : (isset($payload['amount_cents']) ? (int) $payload['amount_cents'] : (isset($payload['amount']) ? Money::toCents($payload['amount']) : (int) $existing['total_amount_cents']));

        $reqCurrency = null;
        if (isset($payload['original_currency_code'])) {
            $reqCurrency = (string) $payload['original_currency_code'];
        } elseif (isset($payload['original_currency'])) {
            $reqCurrency = (string) $payload['original_currency'];
        } elseif (isset($payload['currency'])) {
            $reqCurrency = (string) $payload['currency'];
        }

        $originalCurrencyCode = $existing['original_currency_code'] ?? null;
        $originalAmountCents = $existing['original_amount_cents'] ?? null;
        $exchangeRate = $existing['exchange_rate'] !== null ? (float) $existing['exchange_rate'] : null;

        if ($reqCurrency !== null) {
            $reqCode = strtoupper(trim($reqCurrency));
            if ($reqCode === '' || $reqCode === strtoupper(trim($baseCurrency))) {
                $originalCurrencyCode = null;
                $originalAmountCents = null;
                $exchangeRate = null;
            } else {
                if (!CurrencyService::isSupported($reqCode)) {
                    throw new InvalidArgumentException("Unsupported currency code '{$reqCode}'.", 422);
                }
                $originalCurrencyCode = $reqCode;
                if (isset($payload['original_amount_cents'])) {
                    $originalAmountCents = (int) $payload['original_amount_cents'];
                } elseif (isset($payload['original_amount'])) {
                    $originalAmountCents = Money::toCents($payload['original_amount']);
                } elseif (isset($payload['amount'])) {
                    $originalAmountCents = Money::toCents($payload['amount']);
                } elseif (isset($payload['amount_cents'])) {
                    $originalAmountCents = (int) $payload['amount_cents'];
                }

                if (isset($payload['exchange_rate']) && (float) $payload['exchange_rate'] > 0) {
                    $exchangeRate = (float) $payload['exchange_rate'];
                } else {
                    $exchangeRate = CurrencyService::getExchangeRate($originalCurrencyCode, $baseCurrency);
                }

                if (!isset($payload['total_amount_cents']) && $originalAmountCents !== null) {
                    $totalCents = (int) round($originalAmountCents * $exchangeRate);
                }
            }
        }

        if ($totalCents <= 0) {
            throw new InvalidArgumentException("Expense amount must be greater than zero.", 422);
        }

        $expenseDate = isset($payload['expense_date']) && !empty($payload['expense_date'])
            ? (string) $payload['expense_date']
            : $existing['expense_date'];

        $splitType = strtoupper((string) ($payload['split_type'] ?? $existing['split_type']));
        $validSplitTypes = ['EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES', 'ITEMIZED'];
        if (!in_array($splitType, $validSplitTypes, true)) {
            throw new InvalidArgumentException("Invalid split type '{$splitType}'.", 422);
        }

        $categoryId = isset($payload['category_id']) && !empty($payload['category_id'])
            ? (int) $payload['category_id']
            : null;

        $groupMembers = $this->memberRepo->findByGroupId($groupId);
        $validMemberIds = array_map('intval', array_column($groupMembers, 'id'));

        $defaultPayerId = $existing['payers'][0]['member_id'] ?? $validMemberIds[0];
        $payers = $this->resolvePayers($payload, $totalCents, $validMemberIds, (int) $defaultPayerId);
        $splits = $this->resolveSplits($splitType, $payload, $totalCents, $validMemberIds);

        $taxCents = isset($payload['tax_cents']) ? (int) $payload['tax_cents'] : (int) ($existing['tax_cents'] ?? 0);
        $tipCents = isset($payload['tip_cents']) ? (int) $payload['tip_cents'] : (int) ($existing['tip_cents'] ?? 0);
        $discountCents = isset($payload['discount_cents']) ? (int) $payload['discount_cents'] : (int) ($existing['discount_cents'] ?? 0);
        $notes = isset($payload['notes']) ? (is_string($payload['notes']) ? trim($payload['notes']) : null) : ($existing['notes'] ?? null);

        $this->expenseRepo->updateExpense(
            $expenseId,
            $groupId,
            $title,
            $totalCents,
            $splitType,
            $expenseDate,
            $categoryId,
            $payers,
            $splits,
            $taxCents,
            $tipCents,
            $discountCents,
            $notes,
            $originalCurrencyCode,
            $originalAmountCents,
            $exchangeRate
        );

        if ($splitType === 'ITEMIZED' && isset($payload['items']) && is_array($payload['items'])) {
            $this->itemRepo->saveItems($expenseId, $payload['items']);
        }

        // Process optional receipt attachment
        $receiptBase64 = $payload['receipt_base64'] ?? $payload['data_base64'] ?? null;
        if (!empty($receiptBase64)) {
            $receiptFileName = (string) ($payload['receipt_file_name'] ?? $payload['file_name'] ?? 'receipt.jpg');
            $receiptService = new ReceiptService();
            $receiptService->uploadReceiptBase64($groupId, $expenseId, $receiptFileName, (string) $receiptBase64, null);
        }

        return $this->expenseRepo->findDetailsById($expenseId) ?? [];
    }

    /**
     * Export complete group ledger into RFC 4180 compliant CSV format with Spreadsheet Formula Injection protection.
     *
     * @param int $groupId
     * @param string $currencyCode
     * @return string
     */
    public function generateCsv(int $groupId, string $currencyCode = 'INR'): string
    {
        $expenses = $this->expenseRepo->findByGroupId($groupId, false);

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return '';
        }

        // CSV Header
        fputcsv($output, ['ID', 'Date', 'Description', 'Category', 'Split Type', 'Total Amount (' . $currencyCode . ')', 'Paid By', 'Allocations']);

        foreach ($expenses as $exp) {
            $payersList = [];
            foreach ($exp['payers'] as $p) {
                $payerName = CsvSanitizer::sanitize((string) ($p['member_name'] ?? ''));
                $payersList[] = $payerName . ': ' . Money::format((int) ($p['amount_paid_cents'] ?? 0), $currencyCode);
            }

            $splitsList = [];
            foreach ($exp['splits'] as $s) {
                $splitName = CsvSanitizer::sanitize((string) ($s['member_name'] ?? ''));
                $splitsList[] = $splitName . ': ' . Money::format((int) ($s['amount_owed_cents'] ?? 0), $currencyCode);
            }

            $title = CsvSanitizer::sanitize((string) ($exp['title'] ?? ''));
            $categoryName = CsvSanitizer::sanitize((string) ($exp['category']['name'] ?? 'General'));
            $splitType = (string) ($exp['split_type'] ?? 'EQUAL');
            $totalAmount = number_format(((int) ($exp['total_amount_cents'] ?? 0)) / 100, 2, '.', '');
            $paidByStr = CsvSanitizer::sanitize(implode(' | ', $payersList));
            $allocationsStr = CsvSanitizer::sanitize(implode(' | ', $splitsList));

            fputcsv($output, [
                $exp['id'],
                $exp['expense_date'],
                $title,
                $categoryName,
                $splitType,
                $totalAmount,
                $paidByStr,
                $allocationsStr,
            ]);
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent ?: '';
    }

    /**
     * Resolve and validate payers array ensuring sum equals total expense in cents.
     *
     * @param array<string, mixed> $payload
     * @param int $totalCents
     * @param array<int> $validMemberIds
     * @param int $defaultPayerId
     * @return array<array{member_id: int, amount_paid_cents: int}>
     */
    private function resolvePayers(
        array $payload,
        int $totalCents,
        array $validMemberIds,
        int $defaultPayerId
    ): array {
        $payers = [];

        if (isset($payload['payers']) && is_array($payload['payers']) && !empty($payload['payers'])) {
            $payerMap = [];
            $sumPaid = 0;
            foreach ($payload['payers'] as $p) {
                $payerId = (int) ($p['member_id'] ?? $p['id'] ?? 0);
                if (!in_array($payerId, $validMemberIds, true)) {
                    throw new InvalidArgumentException("Payer member ID {$payerId} does not belong to this group.", 422);
                }

                $paidCents = isset($p['amount_paid_cents'])
                    ? (int) $p['amount_paid_cents']
                    : (isset($p['amount_cents']) ? (int) $p['amount_cents'] : Money::toCents($p['amount'] ?? 0));

                if ($paidCents < 0) {
                    throw new InvalidArgumentException("Payer contribution cannot be negative.", 422);
                }

                if ($paidCents > 0) {
                    $payerMap[$payerId] = ($payerMap[$payerId] ?? 0) + $paidCents;
                    $sumPaid += $paidCents;
                }
            }

            if ($sumPaid !== $totalCents) {
                $diff = Money::format(abs($totalCents - $sumPaid));
                $direction = $sumPaid < $totalCents ? 'shortfall' : 'overpayment';
                throw new InvalidArgumentException(
                    "Payer contributions sum to " . Money::format($sumPaid) . " ({$direction} of {$diff} vs total bill " . Money::format($totalCents) . ").",
                    422
                );
            }

            foreach ($payerMap as $mId => $amountCents) {
                $payers[] = [
                    'member_id' => $mId,
                    'amount_paid_cents' => $amountCents,
                ];
            }
        } elseif (isset($payload['paid_by_member_id']) || isset($payload['payer_id']) || isset($payload['payer_member_id'])) {
            $singlePayerId = (int) ($payload['paid_by_member_id'] ?? $payload['payer_id'] ?? $payload['payer_member_id']);
            if (!in_array($singlePayerId, $validMemberIds, true)) {
                throw new InvalidArgumentException("Payer member ID {$singlePayerId} does not belong to this group.", 422);
            }
            $payers[] = [
                'member_id' => $singlePayerId,
                'amount_paid_cents' => $totalCents,
            ];
        } else {
            $payers[] = [
                'member_id' => $defaultPayerId,
                'amount_paid_cents' => $totalCents,
            ];
        }

        return $payers;
    }

    /**
     * Resolve and validate splits array based on split type.
     *
     * @param string $splitType
     * @param array<string, mixed> $payload
     * @param int $totalCents
     * @param array<int> $validMemberIds
     * @return array<array{member_id: int, amount_owed_cents: int, split_value: float|null}>
     */
    private function resolveSplits(
        string $splitType,
        array $payload,
        int $totalCents,
        array $validMemberIds
    ): array {
        $splitsResult = [];

        $extractExactMap = function ($raw): array {
            $map = [];
            if (is_array($raw)) {
                foreach ($raw as $key => $val) {
                    if (is_array($val) && isset($val['member_id'])) {
                        $mId = (int) $val['member_id'];
                        $mVal = $val['amount_owed_cents'] ?? $val['amount_cents'] ?? $val['amount'] ?? $val['value'] ?? 0;
                        $map[$mId] = $mVal;
                    } else {
                        $map[(int) $key] = $val;
                    }
                }
            }
            return $map;
        };

        $extractPercentageMap = function ($raw) use ($totalCents): array {
            $map = [];
            if (is_array($raw)) {
                foreach ($raw as $key => $val) {
                    if (is_array($val) && isset($val['member_id'])) {
                        $mId = (int) $val['member_id'];
                        if (isset($val['percentage'])) {
                            $mVal = (float) $val['percentage'];
                        } elseif (isset($val['split_value']) && $val['split_value'] !== null) {
                            $mVal = (float) $val['split_value'];
                        } elseif (isset($val['value'])) {
                            $mVal = (float) $val['value'];
                        } elseif (isset($val['amount_owed_cents']) && $totalCents > 0) {
                            $mVal = round(((float) $val['amount_owed_cents'] / (float) $totalCents) * 100, 4);
                        } elseif (isset($val['amount_cents']) && $totalCents > 0) {
                            $mVal = round(((float) $val['amount_cents'] / (float) $totalCents) * 100, 4);
                        } else {
                            $mVal = (float) ($val['amount'] ?? 0);
                        }
                        $map[$mId] = $mVal;
                    } else {
                        $map[(int) $key] = (float) $val;
                    }
                }
            }
            return $map;
        };

        $extractSharesMap = function ($raw): array {
            $map = [];
            if (is_array($raw)) {
                foreach ($raw as $key => $val) {
                    if (is_array($val) && isset($val['member_id'])) {
                        $mId = (int) $val['member_id'];
                        if (isset($val['shares'])) {
                            $mVal = (float) $val['shares'];
                        } elseif (isset($val['split_value']) && $val['split_value'] !== null) {
                            $mVal = (float) $val['split_value'];
                        } elseif (isset($val['value'])) {
                            $mVal = (float) $val['value'];
                        } elseif (isset($val['amount_owed_cents']) || isset($val['amount_cents'])) {
                            $mVal = (float) ($val['amount_owed_cents'] ?? $val['amount_cents'] ?? 1);
                        } else {
                            $mVal = (float) ($val['amount'] ?? 1);
                        }
                        $map[$mId] = $mVal;
                    } else {
                        $map[(int) $key] = (float) $val;
                    }
                }
            }
            return $map;
        };

        switch ($splitType) {
            case 'EQUAL':
                if (isset($payload['split_members']) && empty($payload['split_members'])) {
                    throw new InvalidArgumentException("At least one participant must be selected for an equal split.", 422);
                }

                $participants = isset($payload['split_members']) && is_array($payload['split_members']) && !empty($payload['split_members'])
                    ? array_map('intval', $payload['split_members'])
                    : (isset($payload['splits']) && is_array($payload['splits']) && !empty($payload['splits'])
                        ? array_map('intval', array_column($payload['splits'], 'member_id'))
                        : $validMemberIds);

                foreach ($participants as $pId) {
                    if (!in_array($pId, $validMemberIds, true)) {
                        throw new InvalidArgumentException("Split participant ID {$pId} does not belong to this group.", 422);
                    }
                }

                $calculated = SplitCalculator::calculateEqual($totalCents, $participants);
                foreach ($calculated as $mId => $owedCents) {
                    $splitsResult[] = [
                        'member_id' => (int) $mId,
                        'amount_owed_cents' => (int) $owedCents,
                        'split_value' => null,
                    ];
                }
                break;

            case 'EXACT':
                $rawMap = !empty($payload['exact_amounts']) ? $payload['exact_amounts'] : ($payload['splits'] ?? []);
                $exactMap = $extractExactMap($rawMap);

                if (empty($exactMap)) {
                    throw new InvalidArgumentException("Exact splits map is required.", 422);
                }

                foreach (array_keys($exactMap) as $mId) {
                    if (!in_array((int) $mId, $validMemberIds, true)) {
                        throw new InvalidArgumentException("Split participant ID {$mId} does not belong to this group.", 422);
                    }
                }

                $calculated = SplitCalculator::calculateExact($totalCents, $exactMap);
                foreach ($calculated as $mId => $owedCents) {
                    $splitsResult[] = [
                        'member_id' => (int) $mId,
                        'amount_owed_cents' => (int) $owedCents,
                        'split_value' => null,
                    ];
                }
                break;

            case 'PERCENTAGE':
                $rawMap = !empty($payload['percentages']) ? $payload['percentages'] : ($payload['splits'] ?? []);
                $pctMap = $extractPercentageMap($rawMap);

                if (empty($pctMap)) {
                    throw new InvalidArgumentException("Percentage splits map is required.", 422);
                }

                foreach (array_keys($pctMap) as $mId) {
                    if (!in_array((int) $mId, $validMemberIds, true)) {
                        throw new InvalidArgumentException("Split participant ID {$mId} does not belong to this group.", 422);
                    }
                }

                $calculated = SplitCalculator::calculatePercentage($totalCents, $pctMap);
                foreach ($calculated as $mId => $owedCents) {
                    $splitsResult[] = [
                        'member_id' => (int) $mId,
                        'amount_owed_cents' => (int) $owedCents,
                        'split_value' => (float) $pctMap[$mId],
                    ];
                }
                break;

            case 'SHARES':
                $rawMap = !empty($payload['shares']) ? $payload['shares'] : ($payload['splits'] ?? []);
                $sharesMap = $extractSharesMap($rawMap);

                if (empty($sharesMap)) {
                    throw new InvalidArgumentException("Shares splits map is required.", 422);
                }

                foreach (array_keys($sharesMap) as $mId) {
                    if (!in_array((int) $mId, $validMemberIds, true)) {
                        throw new InvalidArgumentException("Split participant ID {$mId} does not belong to this group.", 422);
                    }
                }

                $calculated = SplitCalculator::calculateShares($totalCents, $sharesMap);
                foreach ($calculated as $mId => $owedCents) {
                    $splitsResult[] = [
                        'member_id' => (int) $mId,
                        'amount_owed_cents' => (int) $owedCents,
                        'split_value' => (float) $sharesMap[$mId],
                    ];
                }
                break;

            case 'ITEMIZED':
                $items = $payload['items'] ?? [];
                if (empty($items) || !is_array($items)) {
                    throw new InvalidArgumentException("Itemized split requires a non-empty items list.", 422);
                }

                $taxCents = (int) ($payload['tax_cents'] ?? 0);
                $tipCents = (int) ($payload['tip_cents'] ?? 0);
                $discountCents = (int) ($payload['discount_cents'] ?? 0);

                $itemizedCalc = SplitCalculator::calculateItemized($items, $taxCents, $tipCents, $discountCents);

                foreach ($itemizedCalc['splits'] as $mId => $owedCents) {
                    if (!in_array((int) $mId, $validMemberIds, true)) {
                        throw new InvalidArgumentException("Split participant ID {$mId} does not belong to this group.", 422);
                    }
                    $splitsResult[] = [
                        'member_id' => (int) $mId,
                        'amount_owed_cents' => (int) $owedCents,
                        'split_value' => null,
                    ];
                }
                break;
        }

        return $splitsResult;
    }
}
