<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daily Stock Finish Good (Auto By System)</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:720px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:24px 24px 8px;">
                <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.05em;color:#64748b;text-transform:uppercase;">IT Integrated SOS</p>
                <h1 style="margin:0;font-size:20px;">Daily Stock Finish Good (IT Integrated SOS)</h1>
                <p style="margin:4px 0 0;font-size:14px;color:#475569;">{{ $date->translatedFormat('l, d F Y') }} · data diambil {{ $dailyStock->local_time }} WIB</p>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px 0;font-size:14px;line-height:1.6;">
                @if ($recipientName !== '')
                    <p style="margin:0 0 12px;">Yth. {{ $recipientName }},</p>
                @endif
                <p style="margin:0;">Ini adalah email otomatis dari system IT, sedang kami trial untuk mengirimkan laporan stok harian, yang di update secara otomatis terintegrasi dengan SOS</p>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        @foreach ([['Part dimonitor', $totals['parts'], '#0f172a'], ['Stock < 1.0 hari', $totals['under_one'], '#e11d48'], ['Stock < 1.5 hari', $totals['not_ok'], '#d97706'], ['Over (> 5 hari)', $totals['over'], '#0284c7']] as [$label, $value, $color])
                            <td style="padding:12px;border:1px solid #e2e8f0;border-radius:8px;" width="25%">
                                <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;">{{ $label }}</div>
                                <div style="font-size:22px;font-weight:700;color:{{ $color }};">{{ number_format($value) }}</div>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px 8px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        @foreach ([['STOCK < 1.5 DAY', 'not_ok', $totals['not_ok']], ['STOCK < 1.0 DAY', 'under_one', $totals['under_one']]] as [$title, $key, $total])
                            <td valign="top" width="50%" style="padding:{{ $loop->first ? '0 8px 0 0' : '0 0 0 8px' }};">
                                <p style="margin:0 0 6px;font-size:15px;font-weight:700;">{{ $title }}</p>
                                <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;text-align:center;">
                                    <tr style="font-weight:700;">
                                        <td style="border:1px solid #cbd5e1;background:#e0f2fe;">NO</td>
                                        <td style="border:1px solid #cbd5e1;background:#e0f2fe;">LAST PROCESS</td>
                                        <td style="border:1px solid #cbd5e1;background:#fef3c7;">{{ $date->format('d-M') }}<br>PART</td>
                                    </tr>
                                    @foreach ($summary as $process => $row)
                                        <tr>
                                            <td style="border:1px solid #cbd5e1;">{{ $loop->iteration }}</td>
                                            <td style="border:1px solid #cbd5e1;">{{ $process }}</td>
                                            <td style="border:1px solid #cbd5e1;background:#fffbeb;">{{ $row[$key] > 0 ? number_format($row[$key]) : '-' }}</td>
                                        </tr>
                                    @endforeach
                                    <tr style="font-weight:700;">
                                        <td style="border:1px solid #cbd5e1;"></td>
                                        <td style="border:1px solid #cbd5e1;">TOTAL:</td>
                                        <td style="border:1px solid #cbd5e1;background:#fffbeb;">{{ $total > 0 ? number_format($total) : '-' }}</td>
                                    </tr>
                                </table>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
        @if ($charts !== [])
            <tr>
                <td style="padding:20px 24px 0;">
                    <p style="margin:0 0 2px;font-size:15px;font-weight:700;">Resume bulanan {{ $date->translatedFormat('F Y') }}</p>
                    <p style="margin:0 0 8px;font-size:12px;color:#64748b;">Jumlah part (item) per last process setiap tanggal · garis = total</p>
                    @foreach ($charts as $chart)
                        <img
                            src="{{ $message->embedData($chart['png'], 'resume-'.$loop->iteration.'.png', 'image/png') }}"
                            width="672"
                            alt="{{ $chart['title'] }}"
                            style="display:block;width:100%;max-width:672px;height:auto;margin:0 0 12px;border:1px solid #e2e8f0;border-radius:8px;"
                        >
                    @endforeach
                </td>
            </tr>
        @endif
        <tr>
            <td style="padding:16px 24px 24px;font-size:12px;color:#64748b;">
                Email ini dikirim otomatis oleh aplikasi IT Integrated SOS sesuai jadwal Daily Stock. Coverage = stock (box penuh) ÷ PO/day (box).
            </td>
        </tr>
    </table>
</body>
</html>
