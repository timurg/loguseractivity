<?php
// This file is part of Moodle - http://moodle.org/
//
// MIT License
//
// Copyright (c) 2026 Timur Girfanov/ Proznanie
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

namespace local_loguseractivity\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

class get_user_logs extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid'    => new external_value(PARAM_INT, 'ID пользователя', VALUE_REQUIRED),
            'courseid'  => new external_value(PARAM_INT, 'ID курса', VALUE_REQUIRED),
            'timefrom'  => new external_value(PARAM_INT, 'С какого времени (unix timestamp)', VALUE_DEFAULT, 0),
            'timeto'    => new external_value(PARAM_INT, 'По какое время (unix timestamp)', VALUE_DEFAULT, 0),
            'limit'     => new external_value(PARAM_INT, 'Максимум записей', VALUE_DEFAULT, 800),
        ]);
    }

    public static function execute($userid, $courseid, $timefrom = 0, $timeto = 0, $limit = 800) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'   => $userid,
            'courseid' => $courseid,
            'timefrom' => $timefrom,
            'timeto'   => $timeto,
            'limit'    => $limit,
        ]);

        try {
            // 1. Проверяем существование курса
            $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

            // 2. Проверяем существование пользователя
            $user = $DB->get_record('user', ['id' => $params['userid']], 'id, firstname, lastname, username', MUST_EXIST);

            // 3. Проверяем контекст и права
            $context = \context_course::instance($params['courseid']);
            self::validate_context($context);
            require_capability('report/log:view', $context);

            // 4. Проверяем текущую запись на курс (мягко — только для информации)
            $enrolled = $DB->record_exists_sql(
                "SELECT 1 FROM {user_enrolments} ue
                 JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid AND e.courseid = :courseid",
                ['userid' => $params['userid'], 'courseid' => $params['courseid']]
            );

            // 5. Получаем логи (даже если пользователь сейчас не записан)
            $sql = "SELECT 
                        l.timecreated,
                        l.eventname,
                        l.component,
                        l.action,
                        l.target,
                        l.objectid,
                        l.crud,
                        l.contextlevel,
                        l.contextinstanceid,
                        l.ip,
                        cm.id AS cmid,
                        m.name AS modulename,
                        s.name AS section_name,
                        s.section AS section_number
                    FROM {logstore_standard_log} l
                    LEFT JOIN {course_modules} cm ON cm.id = l.contextinstanceid AND l.contextlevel = 70
                    LEFT JOIN {modules} m ON m.id = cm.module
                    LEFT JOIN {course_sections} s ON s.id = cm.section
                    WHERE l.courseid = :courseid 
                      AND l.userid = :userid";

            $sqlparams = ['courseid' => $params['courseid'], 'userid' => $params['userid']];

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

            $modinfo = get_fast_modinfo($params['courseid']);

            $resultlogs = [];
            $has_viewed = false;

            foreach ($logs as $log) {
                $activity_name = 'Элемент курса';
                $section_name  = !empty($log->section_name) ? $log->section_name : 'Без раздела';

                if (!empty($log->cmid) && isset($modinfo->cms[$log->cmid])) {
                    $cm = $modinfo->cms[$log->cmid];
                    $activity_name = format_string($cm->get_formatted_name(), true, ['context' => $cm->context]);
                }

                $readable = self::build_readable_action($log, $activity_name, $section_name);

                $time_utc = gmdate('Y-m-d\TH:i:s\Z', $log->timecreated);

                $entry = [
                    'time_utc'        => $time_utc,
                    'timestamp'       => (int)$log->timecreated,
                    'readable_action' => $readable,
                    'activity_name'   => $activity_name,
                    'section_name'    => $section_name,
                    'section_number'  => (int)$log->section_number,
                    'activity_type'   => $log->modulename ?? '',
                    'cmid'            => (int)($log->cmid ?? 0),
                    'ip'              => $log->ip ?? '',
                ];

                $resultlogs[] = $entry;

                if (in_array($log->action, ['viewed', 'viewed course', 'submitted', 'started', 'answered', 'attempted'])) {
                    $has_viewed = true;
                }
            }

            return [
                'success'         => true,
                'userid'          => $params['userid'],
                'courseid'        => $params['courseid'],
                'course_fullname' => $course->fullname,
                'total_logs'      => count($resultlogs),
                'has_viewed_any'  => $has_viewed,
                'enrolled'        => $enrolled,           // просто информация
                'logs'            => $resultlogs,
            ];

        } catch (\dml_missing_record_exception $e) {
            if (strpos($e->getMessage() . $e->debuginfo, 'course') !== false) {
                return self::return_error('course_not_found', 'Курс с указанным ID не найден', ['courseid' => $params['courseid']]);
            }
            return self::return_error('user_not_found', 'Пользователь с указанным ID не найден', ['userid' => $params['userid']]);

        } catch (moodle_exception $e) {
            return self::return_error('access_denied', 'Ошибка доступа: ' . $e->getMessage());
        } catch (\Exception $e) {
            return self::return_error('internal_error', 'Внутренняя ошибка: ' . $e->getMessage());
        }
    }

    private static function build_readable_action($log, $activity_name, $section_name) {
        if ($log->target === 'course' || $log->action === 'viewed course') {
            return "Просмотрел курс";
        }
        if ($log->action === 'viewed') {
            $readable = "Просмотрел «{$activity_name}»";
            if ($section_name !== 'Без раздела') {
                $readable .= " в разделе «{$section_name}»";
            }
            return $readable;
        }
        return ucfirst($log->action) . " «{$activity_name}»";
    }

    private static function return_error($errorcode, $message, $data = []) {
        return [
            'success'    => false,
            'errorcode'  => $errorcode,
            'message'    => $message,
            'data'       => $data,
            'logs'       => [],
            'total_logs' => 0,
            'has_viewed_any' => false,
            'enrolled'   => false,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success'         => new external_value(PARAM_BOOL, 'Успешно ли выполнен запрос'),
            'errorcode'       => new external_value(PARAM_TEXT, 'Код ошибки', VALUE_OPTIONAL),
            'message'         => new external_value(PARAM_TEXT, 'Сообщение об ошибке', VALUE_OPTIONAL),
            'userid'          => new external_value(PARAM_INT, 'ID пользователя', VALUE_OPTIONAL),
            'courseid'        => new external_value(PARAM_INT, 'ID курса', VALUE_OPTIONAL),
            'course_fullname' => new external_value(PARAM_TEXT, 'Полное название курса', VALUE_OPTIONAL),
            'total_logs'      => new external_value(PARAM_INT, 'Количество записей'),
            'has_viewed_any'  => new external_value(PARAM_BOOL, 'Было ли взаимодействие'),
            'enrolled'        => new external_value(PARAM_BOOL, 'Записан ли пользователь на курс сейчас', VALUE_OPTIONAL),
            'logs'            => new external_multiple_structure(
                new external_single_structure([
                    'time_utc'        => new external_value(PARAM_TEXT, 'Дата и время в UTC ISO 8601'),
                    'timestamp'       => new external_value(PARAM_INT, 'Unix timestamp'),
                    'readable_action' => new external_value(PARAM_TEXT, 'Читаемое описание действия'),
                    'activity_name'   => new external_value(PARAM_TEXT, 'Название элемента'),
                    'section_name'    => new external_value(PARAM_TEXT, 'Название раздела'),
                    'section_number'  => new external_value(PARAM_INT, 'Номер раздела'),
                    'activity_type'   => new external_value(PARAM_TEXT, 'Тип активности'),
                    'cmid'            => new external_value(PARAM_INT, 'ID course module'),
                    'ip'              => new external_value(PARAM_TEXT, 'IP'),
                ])
            ),
        ]);
    }
}