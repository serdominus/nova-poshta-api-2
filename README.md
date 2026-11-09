# Nova Poshta API 2.0

PHP-клієнт для роботи з API 2.0 Нової пошти.

# Підготовка

## Отримання ключа API

Щоб отримати доступ до API, виконайте два кроки.

1. Зареєструйтеся на сайті [Нової пошти](https://novaposhta.ua).
2. Згенеруйте ключ API на [сторінці налаштувань](https://my.novaposhta.ua/settings/index#apikeys) особистого кабінету.

Використовуйте цей ключ для запитів до API. Опис методів наведено в [документації Нової пошти](https://my.novaposhta.ua/data/API2-200215-1622-28.pdf).

## Встановлення

Потрібен PHP 8.2 або новіший із розширеннями mbstring і SimpleXML. Для стандартного транспорту потрібне розширення cURL. Для PHP без cURL є транспорт `file_get_contents`.

### Git

Клонуйте репозиторій.

```git
git clone https://github.com/serdominus/nova-poshta-api-2
```

### Composer

Додайте пакет до розділу `require` у файлі `composer.json`.
```json
{
    "require": {
        "serdominus/nova-poshta-api-2": "dev-master"
    }
}
```
Після цього запустіть `composer install` або `composer update`.
Пакет також можна додати однією командою.
```
composer require serdominus/nova-poshta-api-2:dev-master
```

### ZIP-архів

[Завантажте архів репозиторію](https://github.com/serdominus/nova-poshta-api-2/archive/master.zip).

# Формати даних

Передавайте параметри запитів у PHP-масивах. Клієнт повертає відповідь як PHP-масив, JSON або XML.

# Використання

## Створення клієнта

```php
use LisDev\Delivery\NovaPoshtaApi2;

require __DIR__ . '/vendor/autoload.php';

$np = new NovaPoshtaApi2('Ваш_ключ_API');
```

## Налаштування клієнта

У конструкторі можна задати мову відповіді, увімкнути винятки при помилках запиту та вибрати HTTP-транспорт.
```php
$np = new NovaPoshtaApi2(
	'Ваш_ключ_API',
	'ua', // Мова відповіді (ua, en або ru за замовчуванням)
	FALSE, // Кидати виняток при помилці запиту (FALSE за замовчуванням)
	'curl' // Транспорт (curl або file_get_contents)
);
```

## Відстеження посилки за номером ТТН

```php
$result = $np->documentsTracking('59000000000000', '0670000000');
```

## Сповіщення про посилку

```php
$result = $np->documentsTrackingWithNotification(
    $trackingNumber,
    $phone,
    storageEndsAt: $confirmedStorageEndsAt,
    recipientLabel: 'Петренко О.',
);
$notification = $result['data'][0]['notification'] ?? null;
```

Приклад використовує стандартний формат `array`. Метод також підтримує `setFormat('json')` і `setFormat('xml')`. Звичайний `documentsTracking()` повертає дані трекінгу без доданого поля.

- `awaiting_pickup` — посилка чекає на отримувача у відділенні або поштоматі. У тексті показуються останні чотири цифри ТТН. Підпис отримувача можна пропустити.
- `storage_ending` — до підтвердженого завершення зберігання лишилося від 0 до 3 календарних днів за київським часом. Відповідь містить `storageEndsAt` у форматі ISO 8601 та `daysRemaining`.
- `null` — для цього статусу або після переданого строку нагадування немає. У XML це порожній елемент `<notification/>`.

Передавайте `storageEndsAt` як `DateTimeImmutable` із підтвердженим часом завершення зберігання для цієї ТТН. Коли такої дати немає, пропустіть параметр. Строк залежить від умов послуги. Метод формує дані для інтеграції, яка вирішує, коли показувати сповіщення.

Для невдалого HTTP-запиту або некоректної відповіді клієнт повертає `success: false` та `errors` у вибраному форматі. Якщо в конструкторі ввімкнено `throwErrors`, клієнт кидає виняток.

## Орієнтовна дата доставки

```php
// Отримуємо ідентифікатор міста за назвою та областю
$sender_city = $np->getCity('Білгород-Дністровський', 'Одеська');
$sender_city_ref = $sender_city['data'][0]['Ref'];
// Отримуємо ідентифікатор міста за назвою та областю
$recipient_city = $np->getCity('Київ', 'Київська');
$recipient_city_ref = $recipient_city['data'][0]['Ref'];
// Дата відправлення
$date = date('d.m.Y');
// Розраховуємо орієнтовну дату доставки між відділеннями
$result = $np->getDocumentDeliveryDate($sender_city_ref, $recipient_city_ref, 'WarehouseWarehouse', $date);
```

## Вартість доставки

```php
// Отримуємо ідентифікатор міста за назвою та областю
$sender_city = $np->getCity('Білгород-Дністровський', 'Одеська');
$sender_city_ref = $sender_city['data'][0]['Ref'];
// Отримуємо ідентифікатор міста за назвою та областю
$recipient_city = $np->getCity('Київ', 'Київська');
$recipient_city_ref = $recipient_city['data'][0]['Ref'];
// Вага вантажу
$weight = 7;
// Оголошена вартість у гривнях
$price = 5450;
// Розраховуємо вартість доставки між відділеннями
$result = $np->getDocumentPrice($sender_city_ref, $recipient_city_ref, 'WarehouseWarehouse', $weight, $price);
```

## Створення експрес-накладної

```php
// Отримуємо список відправників
$senderInfo = $np->getCounterparties('Sender', 1, '', '');
// Для прикладу беремо першого відправника зі списку
$sender = $senderInfo['data'][0];
// Отримуємо список відділень у місті відправника
$senderWarehouses = $np->getWarehouses($sender['City']);
// Створюємо експрес-накладну
$result = $np->newInternetDocument(
    // Дані відправника
    array(
        'FirstName' => $sender['FirstName'],
        'MiddleName' => $sender['MiddleName'],
        'LastName' => $sender['LastName'],
        // Замість FirstName, MiddleName і LastName можна вказати зареєстроване ПІБ відправника або назву компанії
        // Поле Description є у відповіді getCounterparties('Sender', 1, '', '')
        // 'Description' => $sender['Description'],
        // Якщо пропустити Phone, клієнт використає номер контактної особи
        // 'Phone' => '0631112233',
        // Місто відправлення
        // 'City' => 'Білгород-Дністровський',
        // Область відправлення
        // 'Region' => 'Одеська',
        'CitySender' => $sender['City'],
        // Ідентифікатор відділення відправлення (для прикладу беремо перше у списку)
        'SenderAddress' => $senderWarehouses['data'][0]['Ref'],
        // Відділення відправлення за описом
        // 'Warehouse' => $senderWarehouses['data'][0]['Description'],
    ),
    // Дані отримувача
    array(
        'FirstName' => 'Сидір',
        'MiddleName' => 'Сидорович',
        'LastName' => 'Сидоренко',
        'Phone' => '0509998877',
        'City' => 'Київ',
        'Region' => 'Київська',
        'Warehouse' => 'Відділення №3',
    ),
    array(
        // Дата відправлення
        'DateTime' => date('d.m.Y'),
        // Тип доставки можна вибрати з getServiceTypes()
        'ServiceType' => 'WarehouseWarehouse',
        // Форму оплати можна вибрати з getPaymentForms()
        'PaymentMethod' => 'Cash',
        // Платник за доставку
        'PayerType' => 'Recipient',
        // Оголошена вартість вантажу у гривнях
        'Cost' => '500',
        // Кількість місць
        'SeatsAmount' => '1',
        // Опис вантажу
        'Description' => 'Каструля',
        // Тип вантажу можна вибрати з getCargoTypes()
        'CargoType' => 'Cargo',
        // Вага вантажу
        'Weight' => '10',
        // Об’єм вантажу в кубічних метрах
        'VolumeGeneral' => '0.5',
        // Зворотна доставка
        'BackwardDeliveryData' => array(
            array(
                // Платник за зворотну доставку
                'PayerType' => 'Recipient',
                // Тип зворотної доставки
                'CargoType' => 'Money',
                // Сума зворотної доставки
                'RedeliveryString' => 4552,
            )
        )
    )
);
```

## Відділення в місті

```php
// Для точнішого пошуку вкажіть місто й область
$city = $np->getCity('Київ', 'Київська');
$result = $np->getWarehouses($city['data'][0]['Ref']);
```

## Виклик довільного методу

```php
$result = $np
	->model('Назва_моделі')
	->method('Назва_методу')
	->params(array(
		'Назва_параметра_1' => 'Значення_параметра_1',
		'Назва_параметра_2' => 'Значення_параметра_2',
	))
	->execute();
```

# Підтримувані методи за моделями

## Модель InternetDocument

* save
* update
* delete
* getDocumentPrice
* getDocumentDeliveryDate
* getDocumentList
* getDocument
* printDocument
* printMarkings
* newInternetDocument
* generateReport

## Модель TrackingDocument

* documentsTracking
* documentsTrackingWithNotification

## Модель Counterparty

* save
* update
* delete
* cloneLoyaltyCounterpartySender
* getCounterparties
* getCounterpartyAddresses
* getCounterpartyContactPersons
* getCounterpartyByEDRPOU
* getCounterpartyOptions

## Модель ContactPerson

* save
* update
* delete

## Модель Address

* save
* update
* delete
* getCities
* getStreet
* getWarehouses
* getAreas
* findNearestWarehouse

## Модель Common

* getTypesOfCounterparties
* getBackwardDeliveryCargoTypes
* getCargoDescriptionList
* getCargoTypes
* getDocumentStatuses
* getOwnershipFormsList
* getPalletsList
* getPaymentForms
* getTimeIntervals
* getServiceTypes
* getTiresWheelsList
* getTraysList
* getTypesOfPayers
* getTypesOfPayersForRedelivery


## Перевірка

Після `composer install` запустіть `composer test`. Регресійні тести використовують локальні відповіді API й працюють без ключа. Файл `tests/NovaPoshtaApi2Test.php` містить історичні інтеграційні тести PHPUnit 4, деякі з них змінюють дані в обліковому записі.
