@props(['name'=>'grid'])
@php
$paths=[
'grid'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
'students'=>'M2 9l10-6 10 6-10 6-10-6 M5 11v6l7 4 7-4v-6',
'teacher'=>'M12 3a7 7 0 0 0-4 12.7V19h8v-3.3A7 7 0 0 0 12 3z M9 22h6 M9 10h6 M12 7v6',
'users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M22 21v-2a4 4 0 0 0-3-3.9 M16 3.1a4 4 0 0 1 0 7.8',
'calendar'=>'M4 5h16v16H4z M16 3v4 M8 3v4 M4 11h16 M8 15h2 M14 15h2',
'money'=>'M2 5h20v14H2z M6 9H2 M22 9h-4 M6 15H2 M22 15h-4 M12 8a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
'card'=>'M3 5h18v14H3z M3 10h18 M7 15h3',
'book'=>'M12 5C8 2 4 3 2 4v16c3-2 7-2 10 0 3-2 7-2 10 0V4c-3-2-7-2-10 1z M12 5v15',
'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3l-1 3-3 1-2 3 2 2-1 3 3 2 1 3h4l1-3 3-1 3-3-2-2 1-3-3-2-1-3z',
'search'=>'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6',
'bell'=>'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
'user'=>'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M4 21v-2a8 6 0 0 1 16 0v2z',
'plus'=>'M12 5v14 M5 12h14','download'=>'M12 3v12 M7 10l5 5 5-5 M4 17v4h16v-4',
'eye'=>'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12 M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
'edit'=>'M16 3l5 5-12 12-6 1 1-6z M13 6l5 5','trash'=>'M3 6h18 M9 6V3h6v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7',
'check'=>'M4 12l5 5L20 6','close'=>'M5 5l14 14 M19 5L5 19','arrow'=>'M14 5l-7 7 7 7',
'filter'=>'M3 5h18 M6 12h12 M9 19h6','logout'=>'M9 3H3v18h6 M14 8l5 4-5 4 M8 12h11',
'clock'=>'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20 M12 6v6l4 2',
'file'=>'M14 2H4v20h16V8z M14 2v6h6 M8 12h8 M8 16h8',
'menu'=>'M3 5h18 M3 12h18 M3 19h18','lock'=>'M5 10h14v11H5z M8 10V6a4 4 0 0 1 8 0v4',
'refresh'=>'M20 7v-5 M20 7h-5 M4 17v5 M4 17h5 M5 7a8 8 0 0 1 13-2l2 2 M19 17a8 8 0 0 1-13 2l-2-2',
'pin'=>'M12 22s8-8 8-14A8 8 0 0 0 4 8c0 6 8 14 8 14 M12 5a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name]??$paths['grid'] }}"/></svg>
