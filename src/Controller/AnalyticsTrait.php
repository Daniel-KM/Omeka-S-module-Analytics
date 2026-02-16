<?php declare(strict_types=1);

namespace Analytics\Controller;

use Common\Stdlib\PsrMessage;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

trait AnalyticsTrait
{
    /**
     * @var array
     */
    protected $orderByColumn = [
        'sort_by' => null,
        'sort_order' => null,
    ];

    /**
     * List years as key and value from a table.
     *
     * When the option to include dates without value is set, value may be null.
     */
    protected function listYears(string $table, ?int $fromYear = null, ?int $toYear = null, bool $includeEmpty = false, string $field = 'created'): array
    {
        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select("DISTINCT EXTRACT(YEAR FROM $table.$field) AS 'period'")
            // ->select("DISTINCT SUBSTRING($table.$field, 1, 4) AS 'period'")
            ->from($table, $table)
            ->orderBy('period', 'asc');
        // Don't use function YEAR() in where for speed. Extract() is useless here.
        // TODO Add a generated index (doctrine 2.11, so Omeka 4). or simply >= and <=.
        if ($fromYear && $toYear) {
            method_exists($expr, 'between')
                ? $qb->andWhere($expr->between($table . '.' . $field, ':from_date', ':to_date'))
                : $qb->andWhere($expr->andX($expr->gte($table . '.' . $field, ':from_date'), $expr->lte($table . '.' . $field, ':to_date')));
            $qb
                ->setParameters([
                    'from_date' => $fromYear . '-01-01 00:00:00',
                    'to_date' => $toYear . '-12-31 23:59:59',
                ], [
                    'from_date' => \Doctrine\DBAL\ParameterType::STRING,
                    'to_date' => \Doctrine\DBAL\ParameterType::STRING,
                ]);
        } elseif ($fromYear) {
            $qb
                ->andWhere($expr->gte($table . '.' . $field, ':from_date'))
                ->setParameter('from_date', $fromYear . '-01-01 00:00:00', \Doctrine\DBAL\ParameterType::STRING);
        } elseif ($toYear) {
            $qb
                ->andWhere($expr->lte($table . '.' . $field, ':to_date'))
                ->setParameter('to_date', $toYear . '-12-31 23:59:59', \Doctrine\DBAL\ParameterType::STRING);
        }
        $result = $this->connection->executeQuery($qb, $qb->getParameters(), $qb->getParameterTypes())->fetchFirstColumn();

        $result = array_combine($result, $result);
        if (!$includeEmpty || count($result) <= 1) {
            return $result;
        }

        $range = array_fill_keys(range(min($result), max($result)), null);
        return array_replace($range, $result);
    }

    /**
     * List year-months as key and value from a table.
     *
     * When the option to include dates without value is set, value may be null.
     */
    protected function listYearMonths(string $table, ?int $fromYearMonth = null, ?int $toYearMonth = null, bool $includeEmpty = false, string $field = 'created'): array
    {
        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select("DISTINCT EXTRACT(YEAR_MONTH FROM $table.$field) AS 'period'")
            // ->select("DISTINCT CONCAT(SUBSTRING($table.$field, 1, 4), SUBSTRING($table.$field, 6, 2)) AS 'period'")
            ->from($table, $table)
            ->orderBy('period', 'asc');
        // Don't use function YEAR() in where for speed. Extract() is useless here.
        // TODO Add a generated index (doctrine 2.11, so Omeka 4). or simply >= and <=.
        $bind = [];
        $types = [];
        if ($fromYearMonth) {
            $bind['from_date'] = sprintf('%04d-%02d', substr((string) $fromYearMonth, 0, 4), substr((string) $fromYearMonth, 4, 2)) . '-01 00:00:00';
            $types['from_date'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($toYearMonth) {
            $year = (int) substr((string) $toYearMonth, 0, 4);
            $month = (int) substr((string) $toYearMonth, 4, 2) ?: 12;
            $day = $month === 2 ? date('L', mktime(0, 0, 0, 1, 1, $year) ? 29 : 28) : (in_array($month, [4, 6, 9, 11]) ? 30 : 31);
            $bind['to_date'] = sprintf('%04d-%02d-%02d', $year, $month, $day) . ' 23:59:59';
            $types['to_date'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($fromYearMonth && $toYearMonth) {
            method_exists($expr, 'between')
                ? $qb->andWhere($expr->between($table . '.' . $field, ':from_date', ':to_date'))
                : $qb->andWhere($expr->andX($expr->gte($table . '.' . $field, ':from_date'), $expr->lte($table . '.' . $field, ':to_date')));
        } elseif ($fromYearMonth) {
            $qb->andWhere($expr->gte($table . '.' . $field, ':from_date'));
        } elseif ($toYearMonth) {
            $qb->andWhere($expr->lte($table . '.' . $field, ':to_date'));
        }
        $result = $this->connection->executeQuery($qb, $bind, $types)->fetchFirstColumn();
        $result = array_combine($result, $result);
        if (!$includeEmpty || count($result) <= 1) {
            return $result;
        }

        // Fill all the missing months.
        $periods = $result;

        $first = reset($periods);
        $firstDate = $fromYearMonth ?: substr((string) $first, 0, 4) . '01';
        $firstYear = (int) substr((string) $firstDate, 0, 4);
        $firstMonth = (int) substr((string) $firstDate, 4, 2);

        $reversedPeriods = array_reverse($periods);
        $last = reset($reversedPeriods);
        $lastDate = $toYearMonth ?: substr((string) $last, 0, 4) . '12';
        $lastYear = (int) substr((string) $lastDate, 0, 4);
        $lastMonth = (int) substr((string) $lastDate, 4, 2);

        $range = [];

        // Fill months for first year.
        $isSingleYear = $firstYear === $lastYear;
        foreach (range($firstMonth, $isSingleYear ? $lastMonth : 12) as $currentMonth) {
            $range[sprintf('%04d%02d', $firstYear, $currentMonth)] = null;
        }

        // Fill months for intermediate years.
        $hasIntermediateYears = $firstYear + 1 < $lastYear;
        if ($hasIntermediateYears) {
            for ($currentYear = $firstYear + 1; $currentYear < $lastYear - 1; $currentYear++) {
                for ($currentMonth = 1; $currentMonth < 13; $currentMonth++) {
                    $range[sprintf('%04d%02d', $currentYear, $currentMonth)] = null;
                }
            }
        }

        // Fill months for last year.
        if (!$isSingleYear) {
            foreach (range($firstMonth, $lastMonth ?: 12) as $currentMonth) {
                $range[sprintf('%04d%02d', $firstYear, $currentMonth)] = null;
            }
        }

        return array_replace($range, $periods);
    }

    /**
     * Order an array by key specified in the class key.
     *
     * Use strnatcasecmp() to manage string and number naturally.
     */
    protected function orderByColumnString($a, $b): int
    {
        $aa = (string) ($a[$this->orderByColumn['sort_by']] ?? '');
        $bb = (string) ($b[$this->orderByColumn['sort_by']] ?? '');
        $cmp = strnatcasecmp($aa, $bb);
        return $this->orderByColumn['sort_order'] === 'desc' ? -$cmp : $cmp;
    }

    /**
     * Order an array by key specified in the class key.
     */
    protected function orderByColumnNumber($a, $b): int
    {
        $aa = (int) ($a[$this->orderByColumn['sort_by']] ?? 0);
        $bb = (int) ($b[$this->orderByColumn['sort_by']] ?? 0);
        $cmp = $aa <=> $bb;
        return $this->orderByColumn['sort_order'] === 'desc' ? -$cmp : $cmp;
    }

    protected function exportTable(array $table, array $headers, string $output)
    {
        if (!count($table)) {
            $this->messenger()->addError(new PsrMessage(
                'There is no results.' // @translate
            ));
            return null;
        }

        if (!count($headers)) {
            $this->messenger()->addError(new PsrMessage(
                'There is no headers.' // @translate
            ));
            return null;
        }

        switch ($output) {
            case 'csv':
                $writer = WriterEntityFactory::createCSVWriter();
                $writer
                    ->setFieldDelimiter(',')
                    ->setFieldEnclosure('"')
                    // The escape character cannot be set with this writer.
                    // ->setFieldEscape($this->getParam('escape', '\\'))
                    // The end of line cannot be set with csv writer (reader only).
                    // ->setEndOfLineCharacter("\n")
                    ->setShouldAddBOM(true);
                break;
            case 'tsv':
                $writer = WriterEntityFactory::createCSVWriter();
                $writer
                    ->setFieldDelimiter("\t")
                    // Unlike import, chr(0) cannot be used, because it's output.
                    // Anyway, enclosure and escape are used only when there is a tabulation
                    // inside the value, but this is forbidden by the format and normally
                    // never exist.
                    // TODO Check if the value contains a tabulation before export.
                    // TODO Do not use an enclosure for tsv export.
                    ->setFieldEnclosure('"')
                    // The escape character cannot be set with this writer.
                    // ->setFieldEscape($this->getParam('escape', '\\'))
                    // The end of line cannot be set with csv writer (reader only).
                    // ->setEndOfLineCharacter("\n")
                    ->setShouldAddBOM(true);
                break;
            case 'ods':
                $writer = WriterEntityFactory::createODSWriter();
                break;
            case 'xlsx':
                /*
                 $writer = WriterEntityFactory::createXLSXWriter();
                 break;
                 */
            default:
                $this->messenger()->addError(new PsrMessage(
                    'The format "{format}" is not supported to export statistics.', // @translate
                    ['format' => $output]
                ));
                return null;
        }

        $filename = $this->getFilename('analytics', $output);
        // $writer->openToFile($filePath);
        $writer->openToBrowser($filename);

        // TODO Make values translatable (resource class, template).
        $translate = $this->plugin('translate');

        // Output headers.
        foreach ($headers as &$header) {
            $header = $translate($header);
        }
        unset($header);
        $rowFromValues = WriterEntityFactory::createRowFromArray($headers);
        $writer->addRow($rowFromValues);

        // Output rows.
        foreach ($table as $row) {
            $rowFromValues = WriterEntityFactory::createRowFromArray($row);
            $writer->addRow($rowFromValues);
        }

        $writer->close();
        // TODO Return without exit after streaming file.
        exit();
    }

    protected function getFilename($type, $extension): string
    {
        return ($_SERVER['SERVER_NAME'] ?? 'omeka')
            . '-' . $type
            . '-' . date('Ymd-His')
            . '.' . $extension;
    }
}
