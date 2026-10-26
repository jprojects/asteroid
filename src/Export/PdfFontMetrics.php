<?php
declare(strict_types=1);

namespace Quasar\Export;

/**
 * Anchos de carácter (unidades de 1/1000 em) de Helvetica y Helvetica-Bold
 * en codificación WinAnsi, según las AFM estándar de Adobe. Las 14 fuentes
 * base de PDF no se incrustan, pero hacen falta para medir el texto.
 */
final class PdfFontMetrics
{
    private const HELVETICA = '278*32,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556*10,278,278,584,584,584,556,1015,'
        . '667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,'
        . '556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,'
        . '556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,'
        . '278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,'
        . '667*6,1000,722,667*4,278*4,722,722,778*5,584,778,722*4,667,667,611,556*6,889,500,556*4,278*4,556,556,556*5,584,611,556*4,500,556,500';

    private const HELVETICA_BOLD = '278*32,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556*10,333,333,584,584,584,611,975,'
        . '722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,'
        . '556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,'
        . '556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,'
        . '278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,'
        . '722*6,1000,722,667*4,278*4,722,722,778*5,584,778,722*4,667,667,611,556*6,889,556,556*4,278*4,611,611,611*5,584,611,611*4,556,611,556';

    /** @var array<string,list<int>> */
    private static array $cache = [];

    /** @return list<int> 256 anchos */
    public static function widths(bool $bold): array
    {
        $key = $bold ? 'b' : 'r';
        if (!isset(self::$cache[$key])) {
            $out = [];
            foreach (explode(',', $bold ? self::HELVETICA_BOLD : self::HELVETICA) as $item) {
                [$w, $n] = array_pad(explode('*', $item), 2, '1');
                array_push($out, ...array_fill(0, (int) $n, (int) $w));
            }
            if (count($out) !== 256) {
                throw new \LogicException('Tabla de anchos incompleta: ' . count($out));
            }
            self::$cache[$key] = $out;
        }
        return self::$cache[$key];
    }

    /** Ancho en puntos de un texto ya codificado en WinAnsi (cp1252). */
    public static function width(string $winAnsi, float $size, bool $bold = false): float
    {
        $w = self::widths($bold);
        $total = 0;
        $len = strlen($winAnsi);
        for ($i = 0; $i < $len; $i++) {
            $total += $w[ord($winAnsi[$i])];
        }
        return $total * $size / 1000;
    }
}
