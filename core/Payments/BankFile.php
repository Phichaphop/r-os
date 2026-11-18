<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Money;

/**
 * Blue OS standard bank transfer file `BLUE_STANDARD_XLSX_v1` (DEC-31) — used until a bank is chosen; bank-specific templates
 * are added later as new versioned formats without changing this one (DEC-26).
 *
 *   header block : company · batch no · payment type · period · pay date · prepared by · approved by · created at
 *   columns      : no · payee code · name · site · bank · account no (TEXT — leading zeros kept) · amount (2 decimals) · currency
 *   footer       : item count · total
 *
 * Every cell is written as text except the amount; text cells are never formulas (inline strings), so a payee name such as
 * "=HYPERLINK(…)" stays plain text (formula injection, 6.10).
 */
final class BankFile
{
    public const FORMAT = 'BLUE_STANDARD_XLSX_v1';
    public const VERSION = '1';
    public const SHEET = 'โอนเงิน';
    public const COLUMNS = ['ลำดับ', 'รหัสพนักงาน/ผู้รับเงิน', 'ชื่อ-นามสกุล', 'สาขา', 'ธนาคาร', 'เลขที่บัญชี', 'จำนวนเงิน', 'สกุลเงิน'];
    private const TYPE_LABEL = ['payroll' => 'เงินเดือน', 'claim' => 'เคลม/สวัสดิการ', 'ap' => 'เจ้าหนี้'];

    /**
     * Column spec stored in pmt_file_formats (documentation for whoever builds a bank-specific version).
     *
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'sheet' => self::SHEET,
            'header' => ['บริษัท', 'เลขที่ batch', 'ประเภทการจ่าย', 'งวด', 'วันที่จ่าย', 'ผู้จัดทำ', 'ผู้อนุมัติ', 'เวลาสร้าง'],
            'columns' => self::COLUMNS,
            'footer' => ['จำนวนรายการ', 'ยอดรวม'],
            'amount' => 'number, 2 decimals (baht.satang)',
            'account' => 'text, digits only, leading zeros kept',
        ];
    }

    /**
     * @param array{company: string, batch_no: string, payment_type: string, period: string, pay_date: string, prepared: string, approved: string, generated_at: string, currency: string} $h
     * @param list<array{seq: int, payee_code: string, payee_name: string, site: string, bank: string, account: string, amount: int}> $items
     */
    public static function standard(array $h, array $items): string
    {
        $rows = [
            ['บริษัท', $h['company']],
            ['เลขที่ batch', $h['batch_no']],
            ['ประเภทการจ่าย', self::TYPE_LABEL[$h['payment_type']] ?? $h['payment_type']],
            ['งวด', $h['period']],
            ['วันที่จ่าย', $h['pay_date']],
            ['ผู้จัดทำ', $h['prepared']],
            ['ผู้อนุมัติ', $h['approved']],
            ['เวลาสร้าง', $h['generated_at']],
            [],
            self::COLUMNS,
        ];
        $headerRow = count($rows) - 1;
        $total = 0;
        foreach ($items as $it) {
            $total += $it['amount'];
            $rows[] = [(string) $it['seq'], $it['payee_code'], $it['payee_name'], $it['site'], $it['bank'], $it['account'], Money::format($it['amount'], 2, false), $h['currency']];
        }
        $rows[] = [];
        $rows[] = ['จำนวนรายการ', (string) count($items)];
        $rows[] = ['ยอดรวม', '', '', '', '', '', Money::format($total, 2, false), $h['currency']];
        return Xlsx::write([self::SHEET => [
            'headers' => ['ไฟล์โอนเงินมาตรฐาน Blue OS (' . self::FORMAT . ')'],
            'rows' => $rows,
            'money' => [6],
            'bold_rows' => [$headerRow],
            'freeze' => false,
            'widths' => [16, 22, 34, 10, 12, 20, 16, 10],
        ]], 'Blue OS bank transfer file');
    }

    /**
     * Reads a standard file back (self-check before it is stored; also for tests).
     *
     * @return array{count: int, total: int, items: list<array{seq: string, account: string, amount: int}>}
     */
    public static function readTotals(string $bytes): array
    {
        $rows = Xlsx::read($bytes, self::SHEET);
        $items = [];
        $inItems = false;
        $footerCount = -1;
        $footerTotal = -1;
        foreach ($rows as $r) {
            $first = trim((string) ($r[0] ?? ''));
            if ($first === self::COLUMNS[0]) {
                $inItems = true;
                continue;
            }
            if ($first === 'จำนวนรายการ') {
                $footerCount = (int) ($r[1] ?? -1);
                $inItems = false;
                continue;
            }
            if ($first === 'ยอดรวม') {
                $footerTotal = Money::parse((string) ($r[6] ?? ''), 2) ?? -1;
                continue;
            }
            if ($inItems && $first !== '') {
                $items[] = ['seq' => $first, 'account' => (string) ($r[5] ?? ''), 'amount' => Money::parse((string) ($r[6] ?? ''), 2) ?? 0];
            }
        }
        $sum = array_sum(array_column($items, 'amount'));
        return ['count' => $footerCount === count($items) ? $footerCount : -1, 'total' => $footerTotal === $sum ? $sum : -1, 'items' => $items];
    }
}
