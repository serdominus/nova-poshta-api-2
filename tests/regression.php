<?php

declare(strict_types=1);

use LisDev\Delivery\NovaPoshtaApi2;

require dirname(__DIR__) . '/vendor/autoload.php';

set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
    return false;
});

final class TestClient extends NovaPoshtaApi2
{
    public array $requests = [];
    public array $replies = [];

    protected function sendRequest(string $url, string $post, string $contentType): string
    {
        $this->requests[] = [$url, $post, $contentType];
        if (!$this->replies) {
            throw new LogicException('Unexpected Nova Poshta API call.');
        }
        $reply = array_shift($this->replies);
        if ($reply instanceof RuntimeException) {
            throw $reply;
        }
        return is_array($reply) ? json_encode($reply, JSON_THROW_ON_ERROR) : $reply;
    }
}

function expect($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new LogicException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function success(array $data): array
{
    return ['success' => true, 'data' => $data, 'errors' => []];
}

function expectError(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        expect($message, $error->getMessage());
        return;
    }
    throw new LogicException('Expected RuntimeException.');
}

$np = new TestClient('test-key', 'ua');
$number = '5900000001328';
$tracking = success([['Number' => $number, 'StatusCode' => '7']]);
$np->replies = [$tracking, $tracking];
expect($tracking, $np->documentsTracking($number));
$sent = json_decode($np->requests[0][1], true, 512, JSON_THROW_ON_ERROR);
expect([['DocumentNumber' => $number]], $sent['methodProperties']['Documents']);
$notice = $np->documentsTrackingWithNotification($number, '0670000000', recipientLabel: 'Петренко О.')['data'][0]['notification'];
expect('awaiting_pickup', $notice['type']);
expect('*1328', $notice['trackingNumberMasked']);
expect(true, str_contains($notice['message'], 'Петренко О.'));
$sent = json_decode($np->requests[1][1], true, 512, JSON_THROW_ON_ERROR);
expect('0670000000', $sent['methodProperties']['Documents'][0]['Phone']);

$kyiv = new DateTimeZone('Europe/Kyiv');
$deadline = new DateTimeImmutable('+3 days', $kyiv);
$locker = success([['Number' => $number, 'StatusCode' => 8]]);
$delivered = success([['Number' => $number, 'StatusCode' => '9']]);
$np->replies = [$tracking, $locker, $delivered, $tracking];
$np->setFormat('json');
$result = json_decode($np->documentsTrackingWithNotification($number, storageEndsAt: $deadline), true, 512, JSON_THROW_ON_ERROR);
expect('storage_ending', $result['data'][0]['notification']['type']);
expect(3, $result['data'][0]['notification']['daysRemaining']);
expect(true, str_contains($result['data'][0]['notification']['message'], 'через 3 дні'));
expect($deadline->format(DATE_ATOM), $result['data'][0]['notification']['storageEndsAt']);
expect('json', $np->getFormat());

$np->setFormat('xml');
$result = new SimpleXMLElement($np->documentsTrackingWithNotification($number));
expect('awaiting_pickup', (string) $result->data->item->notification->type);
$np->setFormat('array');
expect(null, $np->documentsTrackingWithNotification($number, storageEndsAt: $deadline)['data'][0]['notification']);
expect(null, $np->documentsTrackingWithNotification($number, storageEndsAt: $deadline->modify('-4 days'))['data'][0]['notification']);

$np->replies = ['<root><success>true</success><data/><errors/></root>'];
$np->setFormat('xml');
$np->documentsTracking($number, '1&2');
[$url, $post, $contentType] = end($np->requests);
expect('https://api.novaposhta.ua/v2.0/xml/', $url);
expect('text/xml', $contentType);
$payload = new SimpleXMLElement($post);
expect($number, (string) $payload->methodProperties->Documents->item->DocumentNumber);
expect('1&2', (string) $payload->methodProperties->Documents->item->Phone);

$np->setFormat('array');
$area = $np->getArea('Київська')['data'][0]['Ref'];
$city = ['Ref' => 'city', 'Area' => $area];
$np->replies = [
    success([$city, ['Ref' => 'other', 'Area' => 'other-area']]),
    success([]),
    success([['Ref' => 'ten', 'Description' => 'Відділення №10']]),
    success([['Ref' => 'one', 'Description' => 'Відділення №1, вул. Прикладна']]),
];
$np->setFormat('json');
$result = json_decode($np->getCity('Приклад', 'Київська'), true, 512, JSON_THROW_ON_ERROR);
expect([$city], $result['data']);
$np->setFormat('array');
expect(false, $np->getCity('Невідоме місто')['success']);
expect(false, $np->getWarehouse('city', 'Відділення №1')['success']);
expect('one', $np->getWarehouse('city', 'Відділення №1')['data'][0]['Ref']);

$np->replies = [new RuntimeException('Timed out'), '{invalid'];
$result = $np->getCities();
expect(false, $result['success']);
expect(['Timed out'], $result['errors']);
$np->setFormat('json');
$result = json_decode($np->getCities(), true, 512, JSON_THROW_ON_ERROR);
expect(['Invalid JSON response from Nova Poshta.'], $result['errors']);

$throwing = new TestClient('test-key', 'ua', true);
$throwing->replies = [
    new RuntimeException('Timed out'),
    '<root><success>false</success><errors><item>First</item><item>Second</item></errors></root>',
];
$throwing->setFormat('xml');
expectError(fn () => $throwing->getWarehouse('city'), 'Timed out');
expect('xml', $throwing->getFormat());
expectError(fn () => $throwing->getCities(), "First\nSecond");

$sender = ['CitySender' => 'sender-city', 'Sender' => 'sender', 'SenderAddress' => 'sender-warehouse'];
$recipient = [
    'CityRecipient' => '',
    'CityRef' => 'recipient-city',
    'FirstName' => 'Тест',
    'LastName' => 'Тест',
    'Phone' => '0670000000',
];
$params = ['Description' => 'Тест', 'Weight' => '1', 'Cost' => '100'];
$np->replies = [success([])];
$requestCount = count($np->requests);
$result = json_decode($np->newInternetDocument($sender, $recipient, $params), true, 512, JSON_THROW_ON_ERROR);
expect(false, $result['success']);
expect($requestCount + 1, count($np->requests));
expect('json', $np->getFormat());

$recipient += ['RecipientAddress' => 'recipient-address', 'Recipient' => 'recipient'];
$np->replies = [success([['Ref' => 'document']])];
$result = json_decode($np->newInternetDocument($sender, $recipient, $params), true, 512, JSON_THROW_ON_ERROR);
expect('document', $result['data'][0]['Ref']);
[, $post] = end($np->requests);
$request = json_decode($post, true, 512, JSON_THROW_ON_ERROR);
expect('InternetDocument', $request['modelName']);
expect('recipient-city', $request['methodProperties']['CityRecipient']);
expect('sender-city', $request['methodProperties']['CitySender']);

echo "Regression checks passed.\n";
