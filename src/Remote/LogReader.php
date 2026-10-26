<?php
declare(strict_types=1);

namespace Quasar\Remote;

use Quasar\Core\Logger;

/**
 * Lee las últimas entradas de storage/logs/{canal}-AAAA-MM-DD.log para el panel central.
 * Solo mira el final de cada fichero (TAIL_BYTES), así que es barato aunque los logs sean grandes.
 */
final class LogReader
{
    private const TAIL_BYTES = 1_048_576;
    private const LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\] ([A-Z]+) \[([^\]]*)\] (.*)$/s';

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Entradas de nivel >= $minLevel de los últimos $days días, la más reciente primero.
     * @return list<array{time:string,level:string,channel:string,context:string,message:string,detail:string}>
     */
    public function recent(string $minLevel = 'warning', int $limit = 100, int $days = 7): array
    {
        $min = Logger::LEVELS[$minLevel] ?? Logger::LEVELS['warning'];
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $entries = [];
        foreach ($this->files($days) as $channel => $files) {
            foreach ($files as $file) {
                foreach ($this->parse($file, $channel) as $entry) {
                    if ($entry['time'] >= $since && (Logger::LEVELS[strtolower($entry['level'])] ?? 0) >= $min) {
                        $entries[] = $entry;
                    }
                }
            }
        }
        usort($entries, static fn (array $a, array $b) => strcmp($b['time'], $a['time']));
        return array_slice($entries, 0, $limit);
    }

    /** @return array{error:int,warning:int} entradas de las últimas 24 h (error incluye critical) */
    public function counts24h(): array
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        $counts = ['error' => 0, 'warning' => 0];
        foreach ($this->files(2) as $channel => $files) {
            foreach ($files as $file) {
                foreach ($this->parse($file, $channel) as $e) {
                    if ($e['time'] < $since) {
                        continue;
                    }
                    if ($e['level'] === 'ERROR' || $e['level'] === 'CRITICAL') {
                        $counts['error']++;
                    } elseif ($e['level'] === 'WARNING') {
                        $counts['warning']++;
                    }
                }
            }
        }
        return $counts;
    }

    /** @return array<string,list<string>> canal => ficheros de los últimos $days días */
    private function files(int $days): array
    {
        $out = [];
        $oldest = date('Y-m-d', time() - $days * 86400);
        foreach (glob($this->dir . '/*-????-??-??.log') ?: [] as $file) {
            if (preg_match('/^([a-z0-9_-]+)-(\d{4}-\d{2}-\d{2})\.log$/i', basename($file), $m) && $m[2] >= $oldest) {
                $out[$m[1]][] = $file;
            }
        }
        return $out;
    }

    /** @return list<array{time:string,level:string,channel:string,context:string,message:string,detail:string}> */
    private function parse(string $file, string $channel): array
    {
        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if (!$fh) {
            return [];
        }
        $truncated = $size > self::TAIL_BYTES;
        if ($truncated) {
            fseek($fh, -self::TAIL_BYTES, SEEK_END);
        }
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        if ($truncated) {
            // La primera línea puede estar cortada
            $text = (string) substr($text, (int) strpos($text, "\n") + 1);
        }

        $entries = [];
        $current = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match(self::LINE, $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'time' => $m[1],
                    'level' => $m[2],
                    'channel' => $channel,
                    'context' => $m[3],
                    'message' => mb_substr($m[4], 0, 2000),
                    'detail' => '',
                ];
            } elseif ($current !== null && $line !== '' && strlen($current['detail']) < 8000) {
                // Traza de una excepción (líneas sangradas tras la entrada)
                $current['detail'] .= $line . "\n";
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }
        return $entries;
    }
}
