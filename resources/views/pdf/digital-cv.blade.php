<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { color: #0f172a; font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; }
        h1 { margin: 0 0 6px; font-size: 26px; }
        h2 { margin: 18px 0 8px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; }
        p { margin: 4px 0; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; }
        .badge { float: right; border: 1px solid #cbd5e1; padding: 8px 10px; text-align: right; }
        .metrics { width: 100%; margin: 12px 0; border-collapse: collapse; }
        .metrics td { border: 1px solid #e2e8f0; padding: 7px; }
        .entry { margin-bottom: 10px; page-break-inside: avoid; }
        .entry-title { font-weight: 700; }
        .date { color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
@php
    $skills = array_filter(array_map('trim', explode(',', $form['skills'] ?? '')));
    $languages = array_filter(array_map('trim', explode(',', $form['languages'] ?? '')));
    $manualSections = [
        'Deneyim' => $form['experience'] ?? [],
        'Egitim Ekleri' => $form['education'] ?? [],
        'Manuel Projeler' => $form['projects'] ?? [],
        'Manuel Sertifikalar' => $form['certificates'] ?? [],
    ];
@endphp
<div class="header">
    <div class="badge">
        <strong>KADEME Onayli</strong><br>
        <span class="muted">Dijital CV</span>
    </div>
    <h1>{{ $form['fullName'] ?? 'KADEME Dijital CV' }}</h1>
    <p class="muted">
        {{ $form['email'] ?? '' }}
        @if(!empty($form['phone'])) | {{ $form['phone'] }} @endif
        @if(!empty($form['location'])) | {{ $form['location'] }} @endif
    </p>
    <p class="muted">Uretim tarihi: {{ $generatedAt }}</p>
</div>

<table class="metrics">
    <tr>
        <td><strong>{{ $approved['total_credit'] ?? 0 }}</strong><br><span class="muted">Kredi</span></td>
        <td><strong>{{ $approved['completed_project_count'] ?? count($projects) }}</strong><br><span class="muted">Proje</span></td>
        <td><strong>{{ $approved['badge_count'] ?? count($badges) }}</strong><br><span class="muted">Rozet</span></td>
        <td><strong>{{ $approved['certificate_count'] ?? count($certificates) }}</strong><br><span class="muted">Sertifika</span></td>
    </tr>
</table>

<h2>Profesyonel Ozet</h2>
<p>{{ $form['summary'] ?? 'KADEME programlari ve proje deneyimleriyle olusturulan dijital CV.' }}</p>

<h2>Egitim</h2>
<div class="entry">
    <div class="entry-title">{{ $form['university'] ?? 'Universite' }}</div>
    <div class="muted">{{ $form['department'] ?? '' }} {{ !empty($form['classYear']) ? ' | '.$form['classYear'] : '' }}</div>
</div>

<h2>KADEME Projeleri</h2>
@forelse($projects as $project)
    <div class="entry">
        <div class="entry-title">{{ $project['name'] ?? '-' }}</div>
        <div class="muted">{{ implode(' | ', array_filter([$project['type'] ?? null, $project['period'] ?? null, $project['graduation_status'] ?? $project['status'] ?? null])) }}</div>
        @if(!empty($project['description']))<p>{{ $project['description'] }}</p>@endif
    </div>
@empty
    <p class="muted">Kayitli KADEME proje katilimi bulunamadi.</p>
@endforelse

@if($skills || $languages)
    <h2>Yetkinlikler ve Diller</h2>
    @if($skills)<p><strong>Yetkinlikler:</strong> {{ implode(', ', $skills) }}</p>@endif
    @if($languages)<p><strong>Diller:</strong> {{ implode(', ', $languages) }}</p>@endif
@endif

<h2>Rozetler ve Sertifikalar</h2>
@foreach($badges as $badge)
    <div class="entry"><span class="entry-title">{{ $badge['title_label'] ?? $badge['name'] ?? 'Rozet' }}</span> <span class="muted">{{ $badge['project'] ?? '' }}</span></div>
@endforeach
@foreach($certificates as $certificate)
    <div class="entry"><span class="entry-title">{{ $certificate['type'] ?? 'Sertifika' }}</span> <span class="muted">{{ $certificate['project'] ?? '' }} {{ !empty($certificate['verification_code']) ? ' | Kod: '.$certificate['verification_code'] : '' }}</span></div>
@endforeach

@foreach($manualSections as $title => $items)
    @if(!empty($items))
        <h2>{{ $title }}</h2>
        @foreach($items as $item)
            @if(!empty($item['title']) || !empty($item['description']))
                <div class="entry">
                    <div class="entry-title">{{ $item['title'] ?? '' }}</div>
                    <div class="date">{{ implode(' | ', array_filter([$item['subtitle'] ?? null, $item['date'] ?? null])) }}</div>
                    @if(!empty($item['description']))<p>{{ $item['description'] }}</p>@endif
                </div>
            @endif
        @endforeach
    @endif
@endforeach
</body>
</html>
