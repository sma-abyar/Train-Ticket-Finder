<?php

/** Share raw search results only within one check; never share user messages. */
final class TicketSearchBatch
{
    private $request;
    private array $responses = [];

    public function __construct(callable $request)
    {
        $this->request = $request;
    }

    public static function payload(array $trip): array
    {
        // Ask for one seat to obtain the broad result set. Capacity and display
        // preferences are checked locally for each subscriber. Quota and coupe
        // still select different upstream inventories and must remain separate.
        return [
            'route' => $trip['route'],
            'car_transport' => 0,
            'date' => $trip['date'],
            'return_date' => '',
            'count' => 1,
            'type' => $trip['type'],
            'coupe' => $trip['coupe'],
            'filter' => $trip['filter'],
        ];
    }

    public function get(array $trip)
    {
        $payload = self::payload($trip);
        $key = http_build_query($payload);
        // Also share empty/failed responses, so an outage cannot multiply calls
        // by the number of subscribers. The next batch always requests afresh.
        if (!array_key_exists($key, $this->responses)) {
            $this->responses[$key] = ($this->request)($payload);
        }
        return $this->responses[$key];
    }
}
