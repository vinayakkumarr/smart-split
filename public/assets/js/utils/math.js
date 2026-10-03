/**
 * Smart Split – Client-Side Financial Math & Split Calculation Utilities
 * Enforces strict integer-cent/paise precision and deterministic remainder reconciliation.
 */

/**
 * Convert string or decimal currency value to integer cents/paise.
 * E.g., "10.50" -> 1050, 99.99 -> 9999, 10 -> 1000
 * @param {string|number} amount
 * @returns {number}
 */
export function toCents(amount) {
    if (typeof amount === 'string') {
        const cleaned = amount.replace(/[₹$,€£\s,]/g, '').trim();
        if (cleaned === '' || isNaN(cleaned)) {
            return 0;
        }
        amount = parseFloat(cleaned);
    }
    if (typeof amount !== 'number' || isNaN(amount) || amount < 0) {
        return 0;
    }
    return Math.round(amount * 100);
}

/**
 * Convert integer cents to standard decimal string.
 * E.g., 1050 -> "10.50"
 * @param {number} cents
 * @returns {string}
 */
export function toDecimal(cents) {
    const safeCents = typeof cents === 'number' && !isNaN(cents) ? cents : 0;
    return (safeCents / 100).toFixed(2);
}

/**
 * Calculate Equal Split among member IDs with deterministic penny remainder distribution.
 * @param {number} totalCents
 * @param {number[]} memberIds
 * @returns {Record<number, number>} Map of memberId => owedCents
 */
export function calculateEqual(totalCents, memberIds) {
    if (totalCents <= 0 || !Array.isArray(memberIds) || memberIds.length === 0) {
        return {};
    }

    const sortedIds = [...memberIds].map(Number).sort((a, b) => a - b);
    const count = sortedIds.length;
    const baseCents = Math.floor(totalCents / count);
    const remainder = totalCents % count;

    const result = {};
    sortedIds.forEach((id, index) => {
        const extraPenny = index < remainder ? 1 : 0;
        result[id] = baseCents + extraPenny;
    });

    return result;
}

/**
 * Validate and calculate Exact Split.
 * @param {number} totalCents
 * @param {Record<number, number|string>} exactMap Map of memberId => amount
 * @returns {Record<number, number>}
 */
export function calculateExact(totalCents, exactMap) {
    const result = {};
    let sum = 0;

    for (const [id, val] of Object.entries(exactMap)) {
        const cents = typeof val === 'number' && Number.isInteger(val) ? val : toCents(val);
        result[Number(id)] = cents;
        sum += cents;
    }

    return {
        splits: result,
        allocatedSum: sum,
        allocatedSumCents: sum,
        totalAllocatedCents: sum,
        diffCents: totalCents - sum,
        isValid: sum === totalCents,
    };
}

/**
 * Calculate Percentage Split distributing residual pennies by largest fractional remainder.
 * @param {number} totalCents
 * @param {Record<number, number|string>} percentageMap Map of memberId => percentage (e.g. 33.33)
 * @returns {Record<number, number>}
 */
export function calculatePercentage(totalCents, percentageMap) {
    if (totalCents <= 0) return {};

    const allocations = [];
    let allocatedSum = 0;

    for (const [id, pctVal] of Object.entries(percentageMap)) {
        const pct = parseFloat(pctVal) || 0;
        const exactFloatCents = (totalCents * pct) / 100.0;
        const base = Math.floor(exactFloatCents);
        const fraction = exactFloatCents - base;

        allocations.push({
            memberId: Number(id),
            base,
            fraction,
        });
        allocatedSum += base;
    }

    const shortfall = totalCents - allocatedSum;

    // Sort descending by fractional remainder
    allocations.sort((a, b) => {
        if (b.fraction !== a.fraction) {
            return b.fraction - a.fraction;
        }
        return a.memberId - b.memberId;
    });

    const result = {};
    let penniesGiven = 0;

    for (const item of allocations) {
        const extra = penniesGiven < shortfall ? 1 : 0;
        result[item.memberId] = item.base + extra;
        penniesGiven += extra;
    }

    return result;
}

/**
 * Calculate Shares / Ratio Split.
 * @param {number} totalCents
 * @param {Record<number, number|string>} sharesMap Map of memberId => shareUnits (e.g. 2, 1)
 * @returns {Record<number, number>}
 */
export function calculateShares(totalCents, sharesMap) {
    if (totalCents <= 0) return {};

    let totalShares = 0;
    for (const share of Object.values(sharesMap)) {
        totalShares += parseFloat(share) || 0;
    }

    if (totalShares <= 0) return {};

    const allocations = [];
    let allocatedSum = 0;

    for (const [id, shareVal] of Object.entries(sharesMap)) {
        const share = parseFloat(shareVal) || 0;
        const exactFloatCents = (totalCents * share) / totalShares;
        const base = Math.floor(exactFloatCents);
        const fraction = exactFloatCents - base;

        allocations.push({
            memberId: Number(id),
            base,
            fraction,
        });
        allocatedSum += base;
    }

    const shortfall = totalCents - allocatedSum;

    allocations.sort((a, b) => {
        if (b.fraction !== a.fraction) {
            return b.fraction - a.fraction;
        }
        return a.memberId - b.memberId;
    });

    const result = {};
    let penniesGiven = 0;

    for (const item of allocations) {
        const extra = penniesGiven < shortfall ? 1 : 0;
        result[item.memberId] = item.base + extra;
        penniesGiven += extra;
    }

    return result;
}

/**
 * Calculate an itemized receipt split where individual line items are assigned to members,
 * with proportional allocation of shared taxes, tips, and discounts using Largest Remainder.
 *
 * @param {Array<{name: string, amount_cents: number, member_ids: number[]}>} items
 * @param {number} [taxCents=0]
 * @param {number} [tipCents=0]
 * @param {number} [discountCents=0]
 * @returns {{totalCents: number, subtotalCents: number, taxCents: number, tipCents: number, discountCents: number, splits: Record<number, number>}}
 */
export function calculateItemized(items, taxCents = 0, tipCents = 0, discountCents = 0) {
    if (!Array.isArray(items) || items.length === 0) {
        return { totalCents: 0, subtotalCents: 0, taxCents: 0, tipCents: 0, discountCents: 0, splits: {} };
    }

    let subtotalCents = 0;
    const memberSubtotals = {};

    for (const item of items) {
        const itemAmount = parseInt(item.amount_cents, 10) || 0;
        const memberIds = (item.member_ids || []).map(Number).filter(Boolean);
        if (itemAmount <= 0 || memberIds.length === 0) continue;

        subtotalCents += itemAmount;
        const itemSplit = calculateEqual(itemAmount, memberIds);

        for (const [mId, cents] of Object.entries(itemSplit)) {
            const numId = Number(mId);
            memberSubtotals[numId] = (memberSubtotals[numId] || 0) + cents;
        }
    }

    const netSurchargeCents = (parseInt(taxCents, 10) || 0) + (parseInt(tipCents, 10) || 0) - (parseInt(discountCents, 10) || 0);
    const totalCents = subtotalCents + netSurchargeCents;

    if (netSurchargeCents === 0 || subtotalCents === 0) {
        return {
            totalCents,
            subtotalCents,
            taxCents,
            tipCents,
            discountCents,
            splits: memberSubtotals,
        };
    }

    const allocations = [];
    let allocatedSurchargeSum = 0;

    for (const [mIdStr, subtotal] of Object.entries(memberSubtotals)) {
        const mId = Number(mIdStr);
        const exactFloat = (netSurchargeCents * subtotal) / subtotalCents;
        const base = Math.floor(exactFloat);
        const fraction = exactFloat - base;

        allocations.push({
            memberId: mId,
            base,
            fraction,
        });
        allocatedSurchargeSum += base;
    }

    const shortfall = netSurchargeCents - allocatedSurchargeSum;

    allocations.sort((a, b) => {
        if (shortfall >= 0) {
            if (b.fraction !== a.fraction) return b.fraction - a.fraction;
        } else {
            if (a.fraction !== b.fraction) return a.fraction - b.fraction;
        }
        return a.memberId - b.memberId;
    });

    const finalSplits = {};
    let penniesGiven = 0;
    const step = shortfall >= 0 ? 1 : -1;
    const stepsNeeded = Math.abs(shortfall);

    for (const item of allocations) {
        const extra = penniesGiven < stepsNeeded ? step : 0;
        finalSplits[item.memberId] = memberSubtotals[item.memberId] + item.base + extra;
        if (extra !== 0) penniesGiven++;
    }

    return {
        totalCents,
        subtotalCents,
        taxCents,
        tipCents,
        discountCents,
        splits: finalSplits,
    };
}

/**
 * Calculate an adjusted split where members have fixed positive/negative offsets from an equal base.
 *
 * @param {number} totalCents
 * @param {number[]} memberIds
 * @param {Record<number, number>} [adjustmentsMap={}]
 * @returns {Record<number, number>}
 */
export function calculateAdjustments(totalCents, memberIds, adjustmentsMap = {}) {
    if (totalCents <= 0 || !Array.isArray(memberIds) || memberIds.length === 0) return {};

    let totalAdjustments = 0;
    for (const val of Object.values(adjustmentsMap)) {
        totalAdjustments += parseInt(val, 10) || 0;
    }

    const remainingToSplit = totalCents - totalAdjustments;
    if (remainingToSplit < 0) return {};

    const baseSplits = calculateEqual(remainingToSplit, memberIds);
    const finalSplits = {};

    for (const mId of memberIds) {
        const numId = Number(mId);
        const adj = parseInt(adjustmentsMap[numId], 10) || 0;
        finalSplits[numId] = (baseSplits[numId] || 0) + adj;
    }

    return finalSplits;
}
