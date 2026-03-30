<?php
// This file is part of Moodle - http://moodle.org/

namespace local_loguseractivity\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class get_user_logs extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid'    => new external_value(PARAM_INT, 'ID пользователя'),
            'courseid'  => new external_value(PARAM_INT, 'ID курса'),
            'timefrom'  => new external_value(PARAM_INT, 'С какого времени (unix timestamp)', VALUE_DEFAULT, 0),
            'timeto'    => new external_value(PARAM_INT, 'По какое время (unix timestamp)', VALUE_DEFAULT, 0),
            'limit'     => new external_value(PARAM_INT, 'Максимум записей (макс. 1000)', VALUE_DEFAULT, 500),
        ]);
    }

    public static function execute($userid, $courseid, $timefrom = 0, $timeto = 0, $limit = 500) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'   => $userid,
            'courseid' => $courseid,
            'timefrom' => $timefrom,
            'timeto'   => $timeto,
            'limit'    => $limit,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('report/log:view', $context);

        $sql = "SELECT 
                    l.id,
                    l.timecreated,
                    l.eventname,
                    l.component,
                    l.action,
                    l.target,
                    l.objectid,
                    l.crud,
                    l.edulevel,
                    l.contextlevel,
                    l.contextinstanceid,
                    l.ip,
                    l.objecttable,
                    cm.id AS cmid,
                    m.name AS modulename
                FROM {logstore_standard_log} l
                LEFT JOIN {course_modules} cm 
                       ON cm.id = l.contextinstanceid AND l.contextlevel = 70
                LEFT JOIN {modules} m 
                       ON m.id = cm.module
                WHERE l.courseid = :courseid 
                  AND l.userid = :userid";

        $sqlparams = [
            'courseid' => $params['courseid'],
            'userid'   => $params['userid'],
        ];

        if ($params['timefrom'] > 0) {
            $sql .= " AND l.timecreated >= :timefrom";
            $sqlparams['timefrom'] = $params['timefrom'];
        }
        if ($params['timeto'] > 0) {
            $sql .= " AND l.timecreated <= :timeto";
            $sqlparams['timeto'] = $params['timeto'];
        }

        $sql .= " ORDER BY l.timecreated DESC LIMIT " . (int)$params['limit'];

        $logs = $DB->get_records_sql($sql, $sqlparams);

        $resultlogs = [];
        $has_viewed = false;

        foreach ($logs as $log) {
            $entry = [
                'timecreated' => (int)$log->timecreated,
                'time'        => userdate($log->timecreated),
                'eventname'   => $log->eventname,
                'action'      => $log->action,
                'component'   => $log->component,
                'target'      => $log->target,
                'objectid'    => (int)$log->objectid,
                'crud'        => $log->crud,
                'modulename'  => $log->modulename ?? '',
                'cmid'        => isset($log->cmid) ? (int)$log->cmid : 0,
                'ip'          => $log->ip ?? '',
                'objecttable' => $log->objecttable ?? '',
            ];

            $resultlogs[] = $entry;

            if (in_array($log->action, ['viewed', 'viewed course', 'submitted', 'started', 'answered', 'attempted', 'uploaded'])) {
                $has_viewed = true;
            }
        }

        return [
            'userid'        => $params['userid'],
            'courseid'      => $params['courseid'],
            'total_logs'    => count($resultlogs),
            'has_viewed_any'=> $has_viewed,
            'logs'          => $resultlogs,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'userid'        => new external_value(PARAM_INT, 'ID пользователя'),
            'courseid'      => new external_value(PARAM_INT, 'ID курса'),
            'total_logs'    => new external_value(PARAM_INT, 'Количество записей'),
            'has_viewed_any'=> new external_value(PARAM_BOOL, 'Было ли взаимодействие'),
            'logs'          => new external_multiple_structure(
                new external_single_structure([
                    'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
                    'time'        => new external_value(PARAM_TEXT, 'Дата и время'),
                    'eventname'   => new external_value(PARAM_TEXT, 'Полное имя события'),
                    'action'      => new external_value(PARAM_TEXT, 'Действие'),
                    'component'   => new external_value(PARAM_TEXT, 'Компонент'),
                    'target'      => new external_value(PARAM_TEXT, 'Target'),
                    'objectid'    => new external_value(PARAM_INT, 'ID объекта'),
                    'crud'        => new external_value(PARAM_TEXT, 'CRUD'),
                    'modulename'  => new external_value(PARAM_TEXT, 'Тип активности'),
                    'cmid'        => new external_value(PARAM_INT, 'ID course module'),
                    'ip'          => new external_value(PARAM_TEXT, 'IP'),
                    'objecttable' => new external_value(PARAM_TEXT, 'Таблица объекта'),
                ])
            ),
        ]);
    }
}