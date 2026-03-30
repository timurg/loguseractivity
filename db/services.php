<?php
$functions = [
    'local_loguseractivity_get_user_logs' => [
        'classname'   => 'loguseractivity\external\get_user_logs',
        'description' => 'Возвращает журнал событий пользователя из logstore_standard_log (как в отчёте Журналы)',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities'=> 'report/log:view',
    ],
];

$services = [
    'Log User Activity Service' => [
        'functions' => ['loguseractivity_get_user_logs'],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];