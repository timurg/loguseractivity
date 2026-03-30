<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

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
            'limit'     => new external_value(PARAM_INT, 'Максимум записей', VALUE_DEFAULT, 500),
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

        // Упрощённый и стабильный запрос
        $sql = "SELECT l.id, l.timecreated, l.action, l.component, l.target, 
                       l.objectid, l.crud, l.edulevel, l.contextlevel, 
                       l.contextinstanceid, l.ip, l.info,
                       cm.id AS cmid,
                       m.name AS modulename
                FROM {logstore_standard_log} l
                LEFT JOIN {course_modules} cm 
                       ON cm.id = l.contextinstanceid 
                      AND l.contextlevel = 70
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
                'action'      => $log->action,
                'component'   => $log->component,
                'target'      => $log->target ?? '',
                'objectid'    => (int)$log->objectid,
                'crud'        => $log->crud,
                'modulename'  => $log->modulename ?? '',     // тип активности (resource, page, quiz...)
                'cmid'        => $log->cmid ? (int)$log->cmid : 0,
                'ip'          => $log->ip ?? '',
            ];

            $resultlogs[] = $entry;

            // Определяем, было ли реальное взаимодействие
            if (in_array($log->action, ['viewed', 'viewed course', 'submitted', 'started', 'answered', 'attempted'])) {
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
                    'action'      => new external_value(PARAM_TEXT, 'Действие'),
                    'component'   => new external_value(PARAM_TEXT, 'Компонент'),
                    'target'      => new external_value(PARAM_TEXT, 'Target'),
                    'objectid'    => new external_value(PARAM_INT, 'ID объекта'),
                    'crud'        => new external_value(PARAM_TEXT, 'CRUD'),
                    'modulename'  => new external_value(PARAM_TEXT, 'Тип активности (resource, quiz и т.д.)'),
                    'cmid'        => new external_value(PARAM_INT, 'ID course module'),
                    'ip'          => new external_value(PARAM_TEXT, 'IP адрес'),
                ])
            ),
        ]);
    }
}