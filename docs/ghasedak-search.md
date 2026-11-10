# Ghasedak24 train search verification

Checked on 2026-11-10 against the provider's public site. No accounts, real
passenger details, reservations or purchases were used for the live checks.

## Primary sources

- [Train search page](https://ghasedak24.com/train-ticket)
- [Tehran–Mashhad results page](https://ghasedak24.com/train-ticket/tehran-mashhad)
- [Current search form bundle](https://ghasedak24.com/front-end/dist/assets/TrainSearch-V6HCbCSs.js)
- [Current result bundle](https://ghasedak24.com/front-end/dist/assets/train/result-HpDiUQqo.js)

The asset names are versioned and may change on the next frontend deployment.
The result bundle is the source for the request fields and local display filters;
the marketing page alone does not establish an API contract.

## Observed search contract

The current page still posts form-encoded requests to
`https://ghasedak24.com/train/search_ajax` with `route`, `car_transport`, `date`,
optional `return_date`, `count`, `type`, `coupe` and `filter=0`. Success responses
still expose `data.status=success` and `data.data.departure`.

The quota mapping remains ordinary=0, men=1, women=2. Coupe affects the inventory
and remains part of the request identity. Car transport and round trips are
separate provider flows and are not enabled by this change.

The public form now carries separate adult, child and infant counts, with a total
limit of 12. The bot's monitor count represents required seats; actual age types
and wheelchair needs belong to the passenger/reservation flow.

The frontend filters search results locally by `owner_name`, `iscompartment` and
`compartment_capacity`, departure time, `counting_en`, and price. The numeric `cost`
field is per-person price in toman. A sampled `cost=3590000` was displayed by
`cost_title` as 35,900,000 rial. The bot's price filter therefore uses `cost`, not
the formatted rial amount or `full_price`.

## Live comparisons and limits

Read-only searches for Tehran–Mashhad on 1405-08-20 with counts 1 and 4 returned
identical ticket data for each of coupe=0 and coupe=1. Count 12 returned the same
ticket ID sets and prices; inventory changed over the course of the checks.
A final consecutive comparison on 1405-08-28 with coupe=1 and counts 1 and 12
returned identical IDs, availability and prices, with 8 available services in each.

These observations support requesting the broad count-1 inventory and applying
each subscriber's seat requirement locally. They are observations of these
routes/dates, not a documented provider guarantee for every future API version.
Do not reuse the upstream response between polling cycles, merge different quotas
or coupe settings, or treat observed capacity as a held reservation. Final
availability, compartment allocation and payable totals are validated by the
provider during booking.

Regression tests use local mock APIs, not live inventory. A future upstream
change should be checked against the public bundles and a small read-only sample
before changing the request identity or response adapter.
