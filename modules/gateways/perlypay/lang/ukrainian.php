<?php

/**
 * Perly Pay for WHMCS, українською. WHMCS називає свою українську мову
 * "ukranian" — модуль читає цей файл для обох написань.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'config.api_key' => 'Секретний ключ',
    'config.api_key_help' => 'Perly Pay → Developers → API keys. sk_test_… для тестового режиму, sk_live_… для справжніх грошей. Збережіть, потім:',
    'config.test' => 'Перевірити зʼєднання',
    'config.webhook_secret' => 'Секрет підпису вебхука',
    'config.webhook_help' => 'У Perly Pay → Developers → Webhooks додайте цю адресу з подіями рахунків і повернень, потім вставте сюди її секрет (whsec_…):',
    'config.page' => 'Сторінка оплати',
    'config.page_overlay' => 'Поверх рахунку (оверлей)',
    'config.page_redirect' => 'На pay.perly.io (перехід)',
    'config.page_help' => 'Для оверлею додайте адресу цього WHMCS у Perly Pay → Settings → «Sites that may embed the checkout». На телефонах завжди відкривається сама сторінка.',
    'config.currencies' => 'Валюти',
    'config.currencies_both' => 'USD і EUR',
    'config.currencies_usd' => 'Лише USD',
    'config.currencies_eur' => 'Лише EUR',
    'config.currencies_help' => 'Perly Pay виставляє рахунки лише в USD або EUR. Клієнти з іншою валютою бачать підказку обрати інший спосіб оплати, якщо нижче в «Convert To For Processing» не вибрано USD або EUR.',
    'pay.button' => 'Сплатити криптовалютою',
    'pay.note' => 'Біткоїн, USDC або USDT з будь-якого гаманця.',
    'pay.test_mode' => 'Тестовий режим: справжні гроші не рухаються.',
    'pay.or' => ' або ',
    'pay.currency' => 'Криптовалютою можна сплатити лише в %s. Будь ласка, оберіть інший спосіб оплати.',
    'pay.not_set_up' => 'Оплату криптовалютою ще не налаштовано. Будь ласка, оберіть інший спосіб оплати.',
    'pay.unavailable' => 'Оплата криптовалютою зараз недоступна. Спробуйте ще раз або оберіть інший спосіб оплати.',
    'pay.not_payable' => 'Цей рахунок не чекає на оплату.',
    'pay.changed' => 'Рахунок змінився. Поверніться, оновіть його і спробуйте ще раз.',
    'pay.description' => 'Рахунок №%1$s у %2$s',
    'pay.back' => 'Назад до рахунку',
    'test.no_key' => 'Спершу збережіть секретний ключ: sk_test_… або sk_live_….',
    'test.refused' => 'Perly Pay не прийняв цей ключ. Скопіюйте його ще раз у Developers → API keys.',
    'test.unreachable' => 'Не вдалося звʼязатися з Perly Pay. Спробуйте за хвилину.',
    'test.failed' => 'Perly Pay: %s',
    'test.live' => 'Зʼєднано в робочому режимі.',
    'test.test' => 'Зʼєднано в тестовому режимі.',
    'test.no_webhook' => 'Вебхука цього WHMCS ще немає в Perly Pay: додайте адресу нижче в Developers → Webhooks.',
    'test.webhook_off' => 'Вебхук цього WHMCS вимкнено в Perly Pay. Увімкніть його в Developers → Webhooks.',
    'test.webhook_events' => 'Вебхук не надсилає invoice.fulfilled. Додайте йому події рахунків у Developers → Webhooks.',
    'test.no_secret' => 'Вебхук є в Perly Pay, але його секрет підпису тут не збережено.',
    'test.ok' => 'Вебхук налаштовано.',
];
