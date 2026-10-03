<?php
/**
 * Renders the Smart Split Master Trademark (V2 Kinetic S-Shear)
 *
 * @param string $variant 'horizontal' | 'stacked' | 'symbol' | 'app-icon'
 * @param string $theme   'dark' | 'light' | 'mono-white' | 'mono-black'
 * @param string $height  CSS height value (e.g. '40px', '32px')
 */
function render_smart_split_logo($variant = 'horizontal', $theme = 'dark', $height = '40px') {
    $isLight = ($theme === 'light');
    $isMonoWhite = ($theme === 'mono-white');
    $isMonoBlack = ($theme === 'mono-black');

    $topColor = '#00F59B';
    $botColor = '#FFFFFF';
    $textBold = '#FFFFFF';
    $textMint = '#00F59B';

    if ($isLight) {
        $topColor = '#059669';
        $botColor = '#080C14';
        $textBold = '#080C14';
        $textMint = '#059669';
    } elseif ($isMonoWhite) {
        $topColor = '#FFFFFF';
        $botColor = 'rgba(255,255,255,0.85)';
        $textBold = '#FFFFFF';
        $textMint = '#FFFFFF';
    } elseif ($isMonoBlack) {
        $topColor = '#000000';
        $botColor = 'rgba(0,0,0,0.75)';
        $textBold = '#000000';
        $textMint = '#000000';
    }

    $topWingPath = 'M 24 38 C 24 25.85 33.85 16 46 16 L 86 16 C 98.15 16 108 25.85 108 38 L 108 46 C 108 56.5 99.5 65 89 65 L 66 65 L 48 47 L 82 47 C 85.3 47 88 44.3 88 41 L 88 35 C 88 31.7 85.3 29 82 29 L 46 29 C 41 29 37 33 37 38 Z';

    $symbol = <<<SVG
    <g class="ss-symbol-group">
        <path class="ss-wing-top" d="{$topWingPath}" fill="{$topColor}" />
        <g transform="rotate(180 60 60)">
            <path class="ss-wing-bot" d="{$topWingPath}" fill="{$botColor}" />
        </g>
    </g>
SVG;

    if ($variant === 'symbol') {
        echo <<<SVG
        <svg viewBox="0 0 120 120" height="{$height}" style="width:auto; display:inline-block;" xmlns="http://www.w3.org/2000/svg" class="ss-logo-interactive">
            {$symbol}
        </svg>
SVG;
        return;
    }

    if ($variant === 'app-icon') {
        echo <<<SVG
        <svg viewBox="0 0 120 120" height="{$height}" style="width:auto; display:inline-block;" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="120" rx="26" fill="#080C14" stroke="#1E293B" stroke-width="1.5" />
            <g transform="translate(18, 18) scale(0.70)">{$symbol}</g>
        </svg>
SVG;
        return;
    }

    if ($variant === 'stacked') {
        echo <<<SVG
        <svg viewBox="0 0 320 200" height="{$height}" style="width:auto; display:inline-block;" xmlns="http://www.w3.org/2000/svg" class="ss-logo-interactive">
            <g transform="translate(100, 10)">{$symbol}</g>
            <text x="160" y="172" style="font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; font-size: 38px; text-anchor: middle;">
                <tspan style="font-weight: 800; fill: {$textBold}; letter-spacing: -0.03em;">Smart</tspan><tspan style="font-weight: 500; fill: {$textMint}; letter-spacing: -0.01em;" dx="5">Split</tspan>
            </text>
        </svg>
SVG;
        return;
    }

    // Default: Horizontal
    echo <<<SVG
    <svg viewBox="0 0 680 120" height="{$height}" style="width:auto; display:inline-block;" xmlns="http://www.w3.org/2000/svg" class="ss-logo-interactive">
        <g transform="translate(10, 0)">{$symbol}</g>
        <text x="160" y="82" style="font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; font-size: 58px;">
            <tspan style="font-weight: 800; fill: {$textBold}; letter-spacing: -0.03em;">Smart</tspan><tspan style="font-weight: 500; fill: {$textMint}; letter-spacing: -0.01em;" dx="6">Split</tspan>
        </text>
    </svg>
SVG;
}
?>
