<?php

declare(strict_types=1);

namespace Basalto\Core\Testdata;

use Illuminate\Http\UploadedFile;

/**
 * Bilder für Testdaten, mit GD erzeugt statt heruntergeladen: Profilbilder mit Initialen auf einer Farbe und
 * Titelbilder mit Verlauf und Sechsecken. Liefert sie als hochgeladene Datei, damit sie denselben Weg gehen wie echte
 * (Zuschnitt, WebP, Ablage).
 */
final class TestdataImages
{
    /** @var list<array{0: int, 1: int, 2: int}> */
    private const COLORS = [[44, 47, 51], [214, 87, 46], [61, 120, 99], [52, 101, 164], [140, 82, 160], [190, 150, 60], [96, 108, 118], [178, 64, 84]];

    /** @var list<array{0: array{0: int, 1: int, 2: int}, 1: array{0: int, 1: int, 2: int}}> Verläufe (hell → dunkel) für Profilbilder */
    private const GRADIENTS = [[[214, 96, 56], [143, 47, 20]], [[70, 76, 86], [28, 31, 36]], [[88, 150, 120], [43, 85, 67]], [[84, 140, 205], [36, 80, 127]],
        [[160, 106, 186], [92, 50, 116]], [[214, 166, 86], [138, 99, 38]], [[214, 104, 128], [127, 47, 68]], [[70, 166, 166], [31, 94, 94]]];

    /** Schrift für Initialen — DejaVu kommt mit dompdf (Abhängigkeit des Kerns); fehlt sie, gibt es eine Silhouette. */
    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagewebp') && function_exists('imagepng');
    }

    /**
     * Ein Profilbild in einem von drei Stilen: Initialen auf einem Verlauf, eine Silhouette oder ein Sechseck-Muster.
     * Doppelt so gross gezeichnet und verkleinert, damit Kanten und Schrift glatt werden.
     */
    public static function avatar(TestdataRun $run, string $initials): UploadedFile
    {
        $size = 800;
        [$from, $to] = $run->pick(self::GRADIENTS);
        $image = imagecreatetruecolor($size, $size);
        self::gradient($image, $from, $to, $size, $size);
        $font = base_path(self::FONT);
        $style = $run->weighted(['initialen' => 50, 'silhouette' => 30, 'muster' => 20]);
        if ($style === 'initialen' && ! (function_exists('imagettftext') && is_file($font))) {
            $style = 'silhouette';
        }
        $soft = (int) imagecolorallocatealpha($image, 255, 255, 255, 105);
        imagefilledellipse($image, $run->int(80, 720), $run->int(40, 260), $run->int(260, 480), $run->int(260, 480), $soft);
        if ($style === 'initialen') {
            $text = mb_strtoupper(mb_substr(trim($initials) !== '' ? trim($initials) : '?', 0, 2));
            $box = (array) imagettfbbox(300, 0, $font, $text);
            $width = (int) $box[2] - (int) $box[0];
            $height = (int) $box[1] - (int) $box[7];
            imagettftext($image, 300, 0, (int) (($size - $width) / 2 - (int) $box[0]), (int) (($size + $height) / 2), (int) imagecolorallocate($image, 255, 255, 255), $font, $text);
        } elseif ($style === 'silhouette') {
            $figure = (int) imagecolorallocatealpha($image, 255, 255, 255, 40);
            imagefilledellipse($image, 400, 330, 300, 300, $figure);
            imagefilledellipse($image, 400, 800, 560, 460, $figure);
        } else {
            $line = (int) imagecolorallocatealpha($image, 255, 255, 255, 70);
            imagesetthickness($image, 10);
            for ($y = -60; $y < $size + 120; $y += 150) {
                for ($x = ($y / 150) % 2 === 0 ? 0 : 87; $x < $size + 120; $x += 174) {
                    $points = [];
                    for ($k = 0; $k < 6; $k++) {
                        $points[] = (int) ($x + 80 * cos(M_PI / 3 * $k + M_PI / 6));
                        $points[] = (int) ($y + 80 * sin(M_PI / 3 * $k + M_PI / 6));
                    }
                    imagepolygon($image, $points, $line);
                }
            }
        }
        $small = imagecreatetruecolor(400, 400);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 400, 400, $size, $size);
        imagedestroy($image);

        return self::file($small, 'profilbild.png');
    }

    /**
     * Diagonaler Verlauf über die ganze Fläche.
     *
     * @param  array{0: int, 1: int, 2: int}  $from
     * @param  array{0: int, 1: int, 2: int}  $to
     */
    private static function gradient(\GdImage $image, array $from, array $to, int $width, int $height): void
    {
        $steps = 64;
        for ($i = 0; $i < $steps; $i++) {
            $f = $i / ($steps - 1);
            $mix = static fn (int $a, int $b): int => max(0, min(255, (int) round($a + ($b - $a) * $f)));
            $color = (int) imagecolorallocate($image, $mix($from[0], $to[0]), $mix($from[1], $to[1]), $mix($from[2], $to[2]));
            // Streifen quer zur Diagonale: von oben links nach unten rechts
            $offset = (int) (($width + $height) * $i / $steps);
            $band = (int) ceil(($width + $height) / $steps) + 2;
            imagefilledpolygon($image, [$offset, 0, $offset + $band, 0, $offset + $band - $height, $height, $offset - $height, $height], $color);
        }
    }

    public static function cover(TestdataRun $run): UploadedFile
    {
        [$r, $g, $b] = $run->pick(self::COLORS);
        $image = imagecreatetruecolor(1200, 400);
        for ($x = 0; $x < 1200; $x += 4) {
            $f = $x / 1200;
            $mix = static fn (int $from, int $to): int => max(0, min(255, (int) round($from * (1 - $f) + $to * $f)));
            imagefilledrectangle($image, $x, 0, $x + 3, 399, (int) imagecolorallocate($image, $mix($r, 20), $mix($g, 22), $mix($b, 26)));
        }
        $line = (int) imagecolorallocatealpha($image, 255, 255, 255, 90);
        for ($i = 0; $i < 9; $i++) {
            $cx = $run->int(0, 1200);
            $cy = $run->int(0, 400);
            $size = $run->int(30, 120);
            $points = [];
            for ($k = 0; $k < 6; $k++) {
                $points[] = (int) ($cx + $size * cos(M_PI / 3 * $k));
                $points[] = (int) ($cy + $size * sin(M_PI / 3 * $k));
            }
            imagepolygon($image, $points, $line);
        }

        return self::file($image, 'titelbild.png');
    }

    private static function file(\GdImage $image, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'testdaten');
        // Die Ablage kopiert das Bild — die Vorlage im Temp-Ordner ist am Ende des Prozesses weg (sonst bleiben je Lauf Hunderte liegen)
        register_shutdown_function(static fn () => @unlink($path));
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
