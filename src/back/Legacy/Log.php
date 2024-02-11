<?php

namespace KeepersTeam\Webtlo\Legacy;

use KeepersTeam\Webtlo\Helper;

final class Log
{
    private static array $log = [];

    public static function append(string $message = ''): void
    {
        if (!empty($message)) {
            self::$log[] = date('d.m.Y H:i:s') . ' ' . $message;
        }
    }

    public static function formatRows(array $rows, string $break = '<br />'): string
    {
        $splitWord = '-- DONE --';

        $output   = [];
        $blockNum = 0;
        foreach ($rows as $row) {
            $isSplit = str_contains($row, $splitWord);

            $output[$blockNum][] = $isSplit ? $splitWord : $row;
            if ($isSplit) {
                $output[$blockNum][] = '';
                $blockNum++;
            }
        }

        // Переворачиваем порядок процессов. Последний - вверху.
        $output = array_merge(...array_reverse($output));

        return implode($break, $output) . $break;
    }

    public static function get(string $break = '<br />'): string
    {
        if (!empty(self::$log)) {
            return self::formatRows(self::$log, $break);
        }

        return '';
    }

    public static function write(string $logFile): void
    {
        $dir = Helper::getLogDir();

        $result = is_writable($dir) || mkdir($dir);
        if (!$result) {
            echo "Нет или недостаточно прав для доступа к каталогу logs";
        }

        $logFile = "$dir/$logFile";
        self::move($logFile);
        if ($logFile = fopen($logFile, "a")) {
            fwrite($logFile, self::get("\n"));
            fwrite($logFile, " -- DONE --\n");
            fclose($logFile);
        } else {
            echo "Не удалось создать файл лога.";
        }
    }

    private static function move(string $logFile): void
    {
        // переименовываем файл лога, если он больше 5 Мб
        if (file_exists($logFile) && filesize($logFile) >= 5242880) {
            if (!rename($logFile, preg_replace('|.log$|', '.1.log', $logFile))) {
                echo "Не удалось переименовать файл лога.";
            }
        }
    }
}
