The integration suite runs against a local WordPress installation with WooCommerce and the plugin active. It replaces reputation API calls, email delivery, and payment processing with test doubles. Each scenario creates and removes its own orders, cart product, and cache entries.

Load WordPress before running the suite:

```sh
UVB_RUN_CHECKOUT_TESTS=1 php -r 'require "/path/to/wp-load.php"; require "/path/to/plugin/tests/checkout-regressions.php";'
```

Set `UVB_CHECKOUT_SCENARIO=FrontendRetryDecisions` to run that scenario alone. The scenario names are listed in `checkout-regressions.php`. Set `UVB_CHECKOUT_REVERSE=1` to check for dependencies on execution order.

`helpers.php` contains the request builders and synthetic gateway. `fixture.php` prepares and cleans up each scenario. `scenarios.php` contains the assertions. A failed assertion or an exception is recorded in the JSON report; later scenarios still run.
