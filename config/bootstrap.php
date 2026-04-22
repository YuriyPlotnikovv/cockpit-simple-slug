<?php

$app->on('content.item.save.before', function ($modelName, &$data, $isUpdate) use ($app) {
    $model = $app->module('content')->model($modelName);
    $collection = ($model['type'] ?? '') === 'singleton' ? 'content/singletons' : "content/collections/{$modelName}";
    $fields = $model['fields'] ?? [];

    $translations = [];
    foreach ($app->helper('locales')->locales(true) as $translation => $loc) {
        $translations[] = ($translation === 'default') ? '' : ('_' . $translation);
    }

    $storage = $app->dataStorage;
    $utils = $app->helper('utils');

    $transliterate = static function ($value) {
        $translitMap = [
            'А' => 'A',
            'Б' => 'B',
            'В' => 'V',
            'Г' => 'G',
            'Д' => 'D',
            'Е' => 'E',
            'Ё' => 'YO',
            'Ж' => 'ZH',
            'З' => 'Z',
            'И' => 'I',
            'Й' => 'Y',
            'К' => 'K',
            'Л' => 'L',
            'М' => 'M',
            'Н' => 'N',
            'О' => 'O',
            'П' => 'P',
            'Р' => 'R',
            'С' => 'S',
            'Т' => 'T',
            'У' => 'U',
            'Ф' => 'F',
            'Х' => 'KH',
            'Ц' => 'TS',
            'Ч' => 'CH',
            'Ш' => 'SH',
            'Щ' => 'SHCH',
            'Ъ' => '',
            'Ы' => 'Y',
            'Ь' => '',
            'Э' => 'E',
            'Ю' => 'YU',
            'Я' => 'YA',
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ё' => 'yo',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'kh',
            'ц' => 'ts',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'shch',
            'ъ' => '',
            'ы' => 'y',
            'ь' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',
        ];

        $value = (string)$value;
        $value = strtr($value, $translitMap);
        if (function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($tmp !== false) {
                $value = $tmp;
            }
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');
        return $value === '' ? (string)time() : $value;
    };

    $makeSlug = static function ($value) use ($transliterate, $utils) {
        $trans = $transliterate($value);
        if (is_object($utils) && (method_exists($utils, 'sluggify') || method_exists($utils, 'slugify'))) {
            return method_exists($utils, 'sluggify') ? $utils->sluggify($trans) : $utils->slugify($trans);
        }
        return $trans;
    };

    foreach ($fields as $field) {
        if (empty($field['opts']['slugField'])) {
            continue;
        }

        $slugFieldBase = $field['opts']['slugField'];
        $slugFieldNameBase = $field['name'];

        foreach ($translations as $translation) {
            $sourceKey = $slugFieldBase . $translation;
            $targetKey = $slugFieldNameBase . $translation;

            if (empty($data[$sourceKey])) {
                continue;
            }

            $valueForSlug = $data[$sourceKey] ?? '';
            $baseSlug = $makeSlug($valueForSlug);

            $trySlug = $baseSlug;
            $suffix = 0;
            if (is_object($storage)) {
                do {
                    $filter = [$targetKey => ['$regex' => '^' . preg_quote($trySlug, '/') . '$', '$options' => 'i']];
                    $entries = $storage->find($collection, ['filter' => $filter]);
                    if (is_object($entries) && method_exists($entries, 'toArray')) {
                        $entries = $entries->toArray();
                    } elseif (!is_array($entries) && $entries instanceof Traversable) {
                        $entries = iterator_to_array($entries);
                    } elseif (!is_array($entries)) {
                        $entries = [];
                    }

                    if (!empty($data['_id'])) {
                        $entries = array_filter($entries, static function ($e) use ($data) {
                            return !isset($e['_id']) || (string)$e['_id'] !== (string)$data['_id'];
                        });
                    }

                    $exists = count($entries) > 0;
                    if ($exists) {
                        $suffix++;
                        $trySlug = $baseSlug . '-' . $suffix;
                    }
                } while ($exists && $suffix < 1000);
            }

            $data[$targetKey] = $trySlug;
        }

        break;
    }
});