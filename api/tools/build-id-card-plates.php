<?php
/**
 * Build the ID card background plates from the supplied design artwork.
 *
 *   php api/tools/build-id-card-plates.php <design-dir>
 *
 * <design-dir> holds the designer's originals (they are NOT committed — they are
 * large flattened comps):
 *   MAM SUMMER ID.png       front comp, used ONLY to trace the white/orange divider
 *   MAM SUMMER ID-back.png  back comp, used ONLY to lift the yellow texture
 *   music-motif.png         the doodle layer, transparent, full-card
 *
 * Writes the committed, data-free plates:
 *   assets/img/idcard/front-plate.png   orange field + white shape + motif
 *   assets/img/idcard/back-plate.png    yellow texture, no content
 *
 * ── Why plates rather than CSS ────────────────────────────────────────────
 * The divider is not a shape CSS can state honestly: a shallow straight diagonal,
 * a circular bay for the photograph, a sharp kink, then a second shallower
 * diagonal. Approximating it with border-radius produced a soft blob (visibly
 * wrong), and the exact forms CSS *could* express — clip-path, an inline-SVG mask
 * — are the two things html2canvas silently fails to rasterise, so the Card
 * Studio's print export would not match the screen. A raster plate is traced from
 * the designer's own artwork, so it is faithful by construction, and html2canvas
 * handles raster backgrounds correctly.
 *
 * The plate is generated at the artwork's native 1414×2000, which is ~485 DPI at
 * the 74×105mm trim — well above the 350 DPI the studio exports at.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$srcDir = rtrim($argv[1] ?? '', '/\\');
if ($srcDir === '' || !is_dir($srcDir)) {
    fwrite(STDERR, "usage: php api/tools/build-id-card-plates.php <design-dir>\n");
    exit(1);
}
$outDir = dirname(__DIR__, 2) . '/assets/img/idcard';
if (!is_dir($outDir)) mkdir($outDir, 0775, true);

$frontSrc = $srcDir . '/MAM SUMMER ID.png';
$backSrc  = $srcDir . '/MAM SUMMER ID-back.png';
$motifSrc = $srcDir . '/music-motif.png';
foreach ([$frontSrc, $backSrc, $motifSrc] as $f) {
    if (!is_file($f)) { fwrite(STDERR, "missing: $f\n"); exit(1); }
}

/* ── 1. Trace the divider out of the front comp ──────────────────────────── */
$ref = imagecreatefrompng($frontSrc);
$W = imagesx($ref); $H = imagesy($ref);

/** The brand orange, matched tightly enough that skin tones in the photograph
 *  cannot pass — a loose test traces the boy's face instead of the divider. */
$isOrange = static function (int $c): bool {
    $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
    return $r > 225 && $g > 105 && $g < 175 && $b > 15 && $b < 80;
};

// First orange row per column, requiring a run so a stray pixel can't win.
$prof = [];
$y0 = (int)(0.22 * $H);
for ($x = 0; $x < $W; $x++) {
    $prof[$x] = -1;
    for ($y = $y0; $y < $H - 30; $y++) {
        if (!$isOrange(imagecolorat($ref, $x, $y))) continue;
        $ok = true;
        for ($k = 1; $k <= 25; $k++) if (!$isOrange(imagecolorat($ref, $x, $y + $k))) { $ok = false; break; }
        if ($ok) { $prof[$x] = $y; break; }
    }
}
// Borrow from a neighbour for the anti-aliased edge columns.
for ($x = 0; $x < $W; $x++) if ($prof[$x] < 0) {
    for ($d = 1; $d < 60; $d++) {
        if (($prof[$x + $d] ?? -1) > 0) { $prof[$x] = $prof[$x + $d]; break; }
        if (($prof[$x - $d] ?? -1) > 0) { $prof[$x] = $prof[$x - $d]; break; }
    }
}
/* Median then mean. The comp's orange carries a printed grain, so a strict
   "first orange pixel" test stair-steps along the steep top-right ramp — visibly
   jagged once painted. Median kills the outlier columns (where the photograph's
   shadow still passed the colour test); the short mean that follows takes the
   staircase off the diagonals without rounding off the bay. */
$med = $prof;
$win = 21;
for ($x = 0; $x < $W; $x++) {
    $s = [];
    for ($k = -$win; $k <= $win; $k++) { $i = $x + $k; if (isset($prof[$i]) && $prof[$i] > 0) $s[] = $prof[$i]; }
    if ($s) { sort($s); $med[$x] = $s[intdiv(count($s), 2)]; }
}
$smooth = $med;
$avg = 9;
for ($x = 0; $x < $W; $x++) {
    $t = 0; $n = 0;
    for ($k = -$avg; $k <= $avg; $k++) { $i = $x + $k; if (isset($med[$i]) && $med[$i] > 0) { $t += $med[$i]; $n++; } }
    if ($n) $smooth[$x] = (int)round($t / $n);
}
$prof = $smooth;

/* The top-right ramp is STRAIGHT in the artwork but nearly vertical in places, and
   "first orange row per column" is ill-conditioned on a near-vertical edge: a
   pixel of horizontal grain becomes a large vertical jump, which paints as a torn
   edge. Neither median nor mean can rescue that. Since the segment is provably
   linear (least squares over x∈[74%,98%] fits it to well under a pixel), fit it
   and use the fit, joining the bay's right edge with one straight run. */
$fit = static function (array $p, int $x1, int $x2): array {
    $n = 0; $sx = 0.0; $sy = 0.0; $sxx = 0.0; $sxy = 0.0;
    for ($x = $x1; $x <= $x2; $x++) { $y = $p[$x]; $n++; $sx += $x; $sy += $y; $sxx += $x * $x; $sxy += $x * $y; }
    $m = ($n * $sxy - $sx * $sy) / ($n * $sxx - $sx * $sx);
    return [$m, ($sy - $m * $sx) / $n];
};
[$mR, $bR] = $fit($prof, (int)(0.76 * $W), (int)(0.985 * $W));
$joinFrom = (int)(0.675 * $W);          // last column the bay still governs
$joinTo   = (int)(0.760 * $W);          // first column the fitted line governs
$yFrom = $prof[$joinFrom];
$yTo   = $mR * $joinTo + $bR;
for ($x = $joinFrom + 1; $x < $joinTo; $x++) {
    $t = ($x - $joinFrom) / ($joinTo - $joinFrom);
    $prof[$x] = (int)round($yFrom + $t * ($yTo - $yFrom));
}
for ($x = $joinTo; $x < $W; $x++) $prof[$x] = (int)round($mR * $x + $bR);
printf("right ramp refitted: y=%.5fx+%.1f  (%.2f%%H at x=76%%  ->  %.2f%%H at right edge)\n",
    $mR, $bR, ($mR * $joinTo + $bR) / $H * 100, ($mR * ($W - 1) + $bR) / $H * 100);

/* ── 2. Paint the front plate ────────────────────────────────────────────── */
$plate = imagecreatetruecolor($W, $H);
imagealphablending($plate, false);
imagesavealpha($plate, true);
$orange = imagecolorallocate($plate, 240, 127, 38);
$paper  = imagecolorallocate($plate, 253, 252, 249);
imagefilledrectangle($plate, 0, 0, $W - 1, $H - 1, $orange);
// White above the traced boundary.
for ($x = 0; $x < $W; $x++) {
    $b = max(0, min($H - 1, (int)$prof[$x]));
    if ($b > 0) imageline($plate, $x, 0, $x, $b - 1, $paper);
}

/* ── 3. Composite the doodle motif, CONFINED to the white region ─────────── */
$motif = imagecreatefrompng($motifSrc);
$mw = imagesx($motif); $mh = imagesy($motif);
if ($mw !== $W || $mh !== $H) {
    $tmp = imagecreatetruecolor($W, $H);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    imagefilledrectangle($tmp, 0, 0, $W - 1, $H - 1, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    imagecopyresampled($tmp, $motif, 0, 0, 0, 0, $W, $H, $mw, $mh);
    imagedestroy($motif); $motif = $tmp;
}
// Manual per-pixel composite: the motif must not tint the orange field (it does
// not in the designer's comp), so it is masked to the white side of the divider.
$applied = 0;
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        if ($y >= (int)$prof[$x]) continue;                 // orange side — skip
        $mc = imagecolorat($motif, $x, $y);
        $ma = ($mc >> 24) & 0x7F;                            // 0 opaque … 127 clear
        // The motif is BLACK marks at ~4% opacity (measured: alpha 122–126, rgb
        // 0,0,0) — that faintness is the design. Anything but `>= 127` here throws
        // the whole layer away, which is exactly what a 120 cutoff did.
        if ($ma >= 127) continue;
        $alpha = (127 - $ma) / 127;
        $mr = ($mc >> 16) & 255; $mg = ($mc >> 8) & 255; $mb = $mc & 255;
        $dc = imagecolorat($plate, $x, $y);
        $dr = ($dc >> 16) & 255; $dg = ($dc >> 8) & 255; $db = $dc & 255;
        imagesetpixel($plate, $x, $y, imagecolorallocate(
            $plate,
            (int)round($mr * $alpha + $dr * (1 - $alpha)),
            (int)round($mg * $alpha + $dg * (1 - $alpha)),
            (int)round($mb * $alpha + $db * (1 - $alpha))
        ));
        $applied++;
    }
}
imagepng($plate, $outDir . '/front-plate.png', 9);
printf("front-plate.png  %dx%d  %d KB  (motif pixels blended: %d)\n",
    $W, $H, (int)round(filesize($outDir . '/front-plate.png') / 1024), $applied);

/* ── 4. Back plate: lift the yellow texture, drop all the content ────────── */
$backRef = imagecreatefrompng($backSrc);
$bw = imagesx($backRef); $bh = imagesy($backRef);
// A patch from the top band, which carries texture but no logo, signature or text.
$px = (int)(0.06 * $bw); $py = (int)(0.02 * $bh);
$pw = (int)(0.40 * $bw);  $ph = (int)(0.16 * $bh);
$back = imagecreatetruecolor($W, $H);
imagecopyresampled($back, $backRef, 0, 0, $px, $py, $W, $H, $pw, $ph);
// Mirror-blend a second, differently-scaled copy so stretching one patch over the
// whole card does not read as one obviously smeared gradient.
$second = imagecreatetruecolor($W, $H);
imagecopyresampled($second, $backRef, 0, 0, (int)(0.52 * $bw), (int)(0.03 * $bh), $W, $H, $pw, $ph);
imageflip($second, IMG_FLIP_HORIZONTAL);
imagecopymerge($back, $second, 0, 0, 0, 0, $W, $H, 45);
imagepng($back, $outDir . '/back-plate.png', 9);
printf("back-plate.png   %dx%d  %d KB\n", $W, $H, (int)round(filesize($outDir . '/back-plate.png') / 1024));

/* ── 5. Signature: crop to its ink so it can be placed precisely ─────────── */
$sigSrc = $srcDir . '/sig.png';
if (is_file($sigSrc)) {
    $sg = imagecreatefrompng($sigSrc);
    $sw = imagesx($sg); $sh = imagesy($sg);
    // The supplied file is a full 1414×2000 canvas with the signature somewhere in
    // the middle; cropping to the ink is what lets CSS position it by its own box.
    $x1 = $sw; $y1 = $sh; $x2 = 0; $y2 = 0;
    for ($y = 0; $y < $sh; $y++) for ($x = 0; $x < $sw; $x++) {
        if (((imagecolorat($sg, $x, $y) >> 24) & 0x7F) < 110) {
            if ($x < $x1) $x1 = $x; if ($x > $x2) $x2 = $x;
            if ($y < $y1) $y1 = $y; if ($y > $y2) $y2 = $y;
        }
    }
    $pad = 8;
    $x1 = max(0, $x1 - $pad); $y1 = max(0, $y1 - $pad);
    $x2 = min($sw - 1, $x2 + $pad); $y2 = min($sh - 1, $y2 + $pad);
    $cw = $x2 - $x1 + 1; $ch = $y2 - $y1 + 1;
    $sig = imagecreatetruecolor($cw, $ch);
    imagealphablending($sig, false); imagesavealpha($sig, true);
    imagefilledrectangle($sig, 0, 0, $cw - 1, $ch - 1, imagecolorallocatealpha($sig, 0, 0, 0, 127));
    imagecopy($sig, $sg, 0, 0, $x1, $y1, $cw, $ch);
    imagepng($sig, $outDir . '/signature.png', 9);
    printf("signature.png    %dx%d  %d KB  (cropped from %dx%d)\n",
        $cw, $ch, (int)round(filesize($outDir . '/signature.png') / 1024), $sw, $sh);
}

/* ── 6. Logo: the black lockup used on both faces ────────────────────────── */
foreach (['MAM Career Logo.png', 'MAM Academy Logo.png', 'logo.png'] as $cand) {
    if (!is_file($srcDir . '/' . $cand)) continue;
    $lg = imagecreatefrompng($srcDir . '/' . $cand);
    $lw = imagesx($lg); $lh = imagesy($lg);
    $side = 512;                                     // ample for a 350 DPI print
    $out = imagecreatetruecolor($side, $side);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefilledrectangle($out, 0, 0, $side - 1, $side - 1, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $lg, 0, 0, 0, 0, $side, $side, $lw, $lh);
    imagepng($out, $outDir . '/logo.png', 9);
    printf("logo.png         %dx%d  %d KB  (from %s %dx%d)\n",
        $side, $side, (int)round(filesize($outDir . '/logo.png') / 1024), $cand, $lw, $lh);
    break;
}

/* ── 7. Report the traced geometry, for the stylesheet's benefit ─────────── */
$pct = static fn(int $x): string => sprintf('%.2f', $prof[$x] / $H * 100);
echo "\ntraced divider (as % of card height):\n";
foreach ([0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 99] as $p) {
    $x = min($W - 1, (int)round($p / 100 * $W));
    echo "   x={$p}%  y=" . $pct($x) . "%\n";
}
$deep = 0; $deepX = 0;
for ($x = 0; $x < $W; $x++) if ($prof[$x] > $deep) { $deep = $prof[$x]; $deepX = $x; }
printf("bay bottom: x=%.1f%%  y=%.2f%%   -> photo must sit above this\n", $deepX / $W * 100, $deep / $H * 100);
printf("card ratio: %.4f (1:%.4f)\n", $W / $H, $H / $W);
