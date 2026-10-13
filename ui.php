<?php
/** Подписи кнопок и список районов можно править здесь. */
function regions(): array
{
    return [
        1 => 'Сыктывкар',
        2 => 'Кулӧмдін, Кӧрткерӧс',
        3 => 'Изьва, Чилимдін, Печӧра, Усинск, Инта, Вӧркута',
        4 => 'Мылдін, Вуктыл, Сосногорск, Ухта',
        5 => 'Удора',
        6 => 'Емдін, Емва',
        7 => 'Сыктыв, Сыктывдін',
        8 => 'Луздор, Койгорт',
        9 => 'Перым му',
        10 => 'Кола кӧдж, Яран кытш, Ямал Яран кытш',
        11 => 'Мукӧд',
    ];
}

function keyboard(array $rows): array
{
    return ['one_time' => false, 'inline' => false, 'buttons' => array_map(
        static fn(array $row) => array_map(static fn(string $label) => [
            'action' => ['type' => 'text', 'label' => $label], 'color' => match ($label) {
                'Лэдза' => 'positive',
                'Заводитны', 'Водзӧ' => 'primary',
                default => 'secondary',
            },
        ], $row), $rows
    )];
}

function active_keyboard(?int $peerId = null): array
{
    $keys = keyboard([['Водзӧ'], ['Вежны подув юӧр']]);
    if ($peerId === null) return $keys;
    $st = db()->prepare('SELECT r.id, r.status FROM recordings r
        JOIN speakers s ON s.id = r.speaker_id WHERE s.vk_id = ? ORDER BY r.id DESC LIMIT 1');
    $st->execute([$peerId]);
    $last = $st->fetch();
    if ($last && $last['status'] !== 'rejected') {
        $keys['buttons'][0][] = [
            'action' => [
                'type' => 'text', 'label' => 'Бӧр',
                'payload' => json_encode(['action' => 'undo_last', 'recording_id' => (int)$last['id']], JSON_THROW_ON_ERROR),
            ],
            'color' => 'secondary',
        ];
    }
    return $keys;
}

function ask_region(int $peerId, bool $canGoBack = false): void
{
    $text = "Кӧні тэ чужин? Бӧрйы чужан район:\n\n";
    foreach (regions() as $number => $label) $text .= "$number) $label\n";
    $rows = [
        ['1', '2', '3', '4'], ['5', '6', '7', '8'], ['9', '10', '11'],
    ];
    if ($canGoBack) $rows[] = ['Бӧр'];
    vk_send($peerId, $text . "\nГиж номерсӧ либӧ нимсӧ.", keyboard($rows));
}

function normalize_region(string $text): ?string
{
    $text = mb_strtolower(trim($text));
    foreach (regions() as $number => $label) {
        if ($text === (string)$number || $text === mb_strtolower($label)) return $label;
        foreach (explode(', ', $label) as $place) {
            if ($text === mb_strtolower($place)) return $label;
        }
    }
    return null;
}

function ask_consent(int $peerId): void
{
    vk_send($peerId,
        "Аттьӧ! Медшӧр юалӧм:\n\n" .
        "Лэдзан-ӧ вӧдитчыны тэнад аудиоюӧръясӧн коми синтезатор да " .
        "тӧдмалысь система велӧдӧм вылӧ? Аудиоюӧръясыс сетчӧны " .
        "Финн-йӧгралӧн регион кывъяссӧ лыдпасаланінӧ (FU-Lab'ӧ).\n\n" .
        "Личкы «Лэдза» либӧ «Ог лэдз».", keyboard([['Лэдза', 'Ог лэдз']])
    );
}

function show_phrase(int $peerId, array $phrase): void
{
    vk_send(
        $peerId,
        "«" . $phrase['text'] . "»\n\u{2800}\n\u{2800}\n\u{2800}\n☼ ☼ ☼",
        active_keyboard($peerId)
    );
}
