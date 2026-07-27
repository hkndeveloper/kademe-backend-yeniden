<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $subject ?? 'KADEME' }}</title>
</head>
<body style="margin:0;background:#f6f7fb;color:#172033;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        {{ $preheader ?? 'KADEME basvuru bilgilendirmesi' }}
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="background:#111827;color:#ffffff;padding:22px 28px;">
                            <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;font-weight:700;color:#cbd5e1;">KADEME</div>
                            <h1 style="margin:8px 0 0;font-size:24px;line-height:1.25;font-weight:800;">{{ $title ?? $subject ?? 'Basvuru Bilgilendirmesi' }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            @if(!empty($intro))
                                <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#334155;">{{ $intro }}</p>
                            @endif

                            @if(!empty($lines) && is_array($lines))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 22px;">
                                    @foreach($lines as $line)
                                        <tr>
                                            <td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:13px;font-weight:700;color:#64748b;width:38%;">{{ $line['label'] ?? '' }}</td>
                                            <td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:14px;color:#172033;">{{ $line['value'] ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if(!empty($body))
                                <p style="margin:0 0 22px;font-size:14px;line-height:1.7;color:#334155;white-space:pre-line;">{{ $body }}</p>
                            @endif

                            @if(!empty($action_url) && !empty($action_text))
                                <p style="margin:24px 0;">
                                    <a href="{{ $action_url }}" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:10px;padding:12px 18px;font-size:14px;font-weight:700;">{{ $action_text }}</a>
                                </p>
                            @endif

                            <p style="margin:24px 0 0;font-size:12px;line-height:1.6;color:#64748b;">{{ $footer ?? 'Bu e-posta KADEME sistemi tarafindan otomatik olarak gonderilmistir.' }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>