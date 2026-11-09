# Nova Poshta API 2.0
Класс предоставляет доступ к функциям API 2.0 службы доставки Новая Почта

# Подготовка
## Получение ключа API
Для использования API необходимо: 
* зарегистрироваться на сайте [Новой Почты](http://novaposhta.ua)
* На [странице настроек](https://my.novaposhta.ua/settings/index#apikeys) в личном кабинете сгенерировать ключ для работы с API 

После получения ключа API предоставляется возможность использовать все методы класса [официальной из документации](https://my.novaposhta.ua/data/API2-200215-1622-28.pdf)

## Установка последней версии класса для работы с API

Потрібен PHP 8.2 або новіший із розширеннями mbstring і SimpleXML. Для стандартного транспорту потрібне розширення cURL. Для PHP без cURL є транспорт `file_get_contents`.
### Git
Необходимо выполнить в командной строке
```git
git clone https://github.com/serdominus/nova-poshta-api-2
```
### Composer
Необходимо создать файл ``composer.json`` со следующим содержанием  
```json
{
    "require": {
        "serdominus/nova-poshta-api-2": "dev-master"
    }
}
```
и запустить из командной строки команду ``php composer.phar install`` или ``php composer.phar update``
Или выполнить в командной строке 
```
composer require serdominus/nova-poshta-api-2:dev-master
```
### Альтернативная установка
Необходимо скачать архив по ссылке
```
https://github.com/serdominus/nova-poshta-api-2/archive/master.zip
```
# Форматы данных
Для входящих данных используются PHP массивы, ответ сервера может быть получен в формате:
* как PHP массив
* JSON
* XML

# Использование 
## Создание экземпляра класса
```php
use LisDev\Delivery\NovaPoshtaApi2;

require __DIR__ . '/vendor/autoload.php';

$np = new NovaPoshtaApi2('Ваш_ключ_API_2.0');
```

## Создание экземпляра класса (с расширенными параметрами)
Рекомендуется использовать, если необходимо получать данные на языке, отличном от русского, выбрасывать Exception при ошибке запроса, или при отсутствии установленной библиотеки curl на сервере
```php
$np = new NovaPoshtaApi2(
	'Ваш_ключ_API_2.0',
	'ru', // Язык возвращаемых данных: ru (default) | ua | en
	FALSE, // При ошибке в запросе выбрасывать Exception: FALSE (default) | TRUE
	'curl' // Транспорт: curl або file_get_contents
);
```

## Получение информации о трек-номере
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

## Получение сроков доставки
```php
// Получение кода города по названию города и области
$sender_city = $np->getCity('Белгород-Днестровский', 'Одесская');
$sender_city_ref = $sender_city['data'][0]['Ref'];
// Получение кода города по названию города и области
$recipient_city = $np->getCity('Киев', 'Киевская');
$recipient_city_ref = $recipient_city['data'][0]['Ref'];
// Дата отправки груза
$date = date('d.m.Y');
// Получение ориентировочной даты прибытия груза между складами в разных городах
$result = $np->getDocumentDeliveryDate($sender_city_ref, $recipient_city_ref, 'WarehouseWarehouse', $date);	
```
## Получение стоимости доставки
```php
// Получение кода города по названию города и области
$sender_city = $np->getCity('Белгород-Днестровский', 'Одесская');
$sender_city_ref = $sender_city['data'][0]['Ref'];
// Получение кода города по названию города и области
$recipient_city = $np->getCity('Киев', 'Киевская');
$recipient_city_ref = $recipient_city['data'][0]['Ref'];
// Вес товара
$weight = 7;
// Цена в грн
$price = 5450;
// Получение стоимости доставки груза с указанным весом и стоимостью между складами в разных городах 
$result = $np->getDocumentPrice($sender_city_ref, $recipient_city_ref, 'WarehouseWarehouse', $weight, $price);
```
## Генерирование новой электронной накладной
```php
// Перед генерированием ЭН необходимо получить данные отправителя
// Получение всех отправителей
$senderInfo = $np->getCounterparties('Sender', 1, '', '');
// Выбор отправителя в конкретном городе (в данном случае - в первом попавшемся)
$sender = $senderInfo['data'][0];
// Информация о складе отправителя
$senderWarehouses = $np->getWarehouses($sender['City']);
// Генерирование новой накладной
$result = $np->newInternetDocument(
    // Данные отправителя
    array(
        // Данные пользователя
        'FirstName' => $sender['FirstName'],
        'MiddleName' => $sender['MiddleName'],
        'LastName' => $sender['LastName'],
        // Вместо FirstName, MiddleName, LastName можно ввести зарегистрированные ФИО отправителя или название фирмы для юрлиц
        // (можно получить, вызвав метод getCounterparties('Sender', 1, '', ''))
        // 'Description' => $sender['Description'],
        // Необязательное поле, в случае отсутствия будет использоваться из данных контакта
        // 'Phone' => '0631112233',
        // Город отправления
        // 'City' => 'Белгород-Днестровский',
        // Область отправления
        // 'Region' => 'Одесская',
        'CitySender' => $sender['City'],
        // Отделение отправления по ID (в данном случае - в первом попавшемся)
        'SenderAddress' => $senderWarehouses['data'][0]['Ref'],
        // Отделение отправления по адресу
        // 'Warehouse' => $senderWarehouses['data'][0]['DescriptionRu'],
    ),
    // Данные получателя
    array(
        'FirstName' => 'Сидор',
        'MiddleName' => 'Сидорович',
        'LastName' => 'Сиродов',
        'Phone' => '0509998877',
        'City' => 'Киев',
        'Region' => 'Киевская',
        'Warehouse' => 'Отделение №3: ул. Калачевская, 13 (Старая Дарница)',
    ),
    array(
        // Дата отправления
        'DateTime' => date('d.m.Y'),
        // Тип доставки, дополнительно - getServiceTypes()
        'ServiceType' => 'WarehouseWarehouse',
        // Тип оплаты, дополнительно - getPaymentForms()
        'PaymentMethod' => 'Cash',
        // Кто оплачивает за доставку
        'PayerType' => 'Recipient',
        // Стоимость груза в грн
        'Cost' => '500',
        // Кол-во мест
        'SeatsAmount' => '1',
        // Описание груза
        'Description' => 'Кастрюля',
        // Тип доставки, дополнительно - getCargoTypes
        'CargoType' => 'Cargo',
        // Вес груза
        'Weight' => '10',
        // Объем груза в куб.м.
        'VolumeGeneral' => '0.5',
        // Обратная доставка
        'BackwardDeliveryData' => array(
            array(
                // Кто оплачивает обратную доставку
                'PayerType' => 'Recipient',
                // Тип доставки
                'CargoType' => 'Money',
                // Значение обратной доставки
                'RedeliveryString' => 4552,
            )
        )
    )
);
```
## Получение складов в определенном городе
```php
// В параметрах указывается город и область (для более точного поиска)
$city = $np->getCity('Киев', 'Киевская');
$result = $np->getWarehouses($city);
```
## Вызов произвольного метода
```php
$result = $np
	->model('Имя_модели')
	->method('Имя_метода')
	->params(array(
		'Имя_параметра_1' => 'Значение_параметра_1',
		'Имя_параметра_2' => 'Значение_параметра_2',
	))
	->execute();
```

# Реализованные методы для работы с моделями

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
* documentsTracking
* newInternetDocument
* generateReport

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
