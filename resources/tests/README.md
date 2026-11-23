# Indexer tests

```bash
bash resources/tests/run.sh
RR_PROXY=/path/to/rr-proxy bash resources/tests/run.sh
```

Needs PHP with the indexer's extensions (`composer install` done), and `mysqld` + `mysql` (MySQL 8.4 or 9.7).
`run.sh` starts a throwaway mysqld under a temporary directory, loads `resources/schema.sql` into it,
runs every test and removes it all again. Nothing of a real deployment (config, database, rr-proxy)
is read or touched. With `RR_PROXY`, the PHP client and the API are also run against a real
rr-proxy on a throwaway database.

| Test | What it checks |
|:---|:---|
| `addr_validation_test.php` | `Protocol::isAddressValid()` on both networks, against real addresses |
| `addr_corpus_check.php` | the same against `fixtures/addr_corpus.json`, the corpus kns-core is checked against |
| `protocol_test.php` | `parseCommand()`: every OP against the states it is valid and not valid for, the memo rules (signature, nonce, price, marker, zone), `commandOutput()`'s first-output rule, prices, and the REG tree path check (`pathTaken()`), on both networks |
| `evm_redact_test.php` | an EVM endpoint's failure as `EvmRpcException` keeps it: every URL cut to its scheme and host, so a paid endpoint's API key (path, query or login) reaches neither the error log nor Telegram; on the messages Guzzle really writes (a closed port, a 403) and on written ones |
| `telegram_redact_test.php` | a failure to send to Telegram as `sendTelegram()` logs it (every message to the admin of the indexer, the registrar and the registrar API goes through it): the bot token cut out of the URL the SDK names (`telegramErrorText()`), on the message the SDK really writes (a closed port) and on written ones; what it sends to a fake Telegram (`php -S`): the chat, and the text cut to 4000 characters |
| `db_test.php` | the schema's keys, the `domain_key` the database generates against `Protocol::treeKey()` (and kns-core's `key_of()`), a REG refused when another name holds its whole tree path (forged), and `BlockScanner::scan()` skipping such a REG with a stub node |
| `xa_test.php` | `Database`'s XA transaction as `indexer.php` and the registrar's batchProcessor run it: prepared, then committed; with nothing written, rolled back and no COMMIT tried; the connection lost before the COMMIT answered false, the transaction left PREPARED with its locks for `checkUncommitedXATX()`, and the next one started under its own id; the connection lost with nothing written answered true, with no error thrown. It kills its own connections, so its log (`logs/xa/`) has their failures |
| `epoch_gate_test.php` | `indexer.php sync` (a copy with a test config) against two fake anchor endpoints (`fake_anchor.php`) a fake rr-proxy (`fake_rrproxy.php`) and a fake coin node (`fake_node.php`): an epoch other than the reviewed one is acted on only when a second endpoint reads it on the same side, a stop is held at the lower of the two readings, an endpoint that disagrees is rotated, an epoch past `PHP_INT_MAX` is an RPC fault; a FATAL error that repeats on every run is told once, then held until `SYNC_STALL_REMIND_SEC` has passed, another one is told at once, and a run that ends without an error ends the one told |
| `rrproxy_client_test.php` | `RustRocksDBProxy` / `SparseMerkleTree` against a real rr-proxy: one writer at a time (BUSY), the idle close and reconnect, a lost batch, root determinism, state verification |
| `api_test.php` | the API of a copy served with `php -S`, against a real rr-proxy and a fake anchor: `/v1.0/resolve` answers only when the rows and the proofs are of one state (the tree committed ahead of MySQL is held back and asked again; a height between checkpoints; lookups while commits land), `/v1.0/info` caches the anchored root whole, a path starting with `//` is no method, `/v1.0/checkpoints` and `/v1.0/history` take a block height only as an integer or a string of digits; all of it through a database user that may only read (`DB_CONFIG_API`) |

Two names sharing a whole 128-bit tree path are not known to exist, so the tests forge one: in
Protocol's own state, and in the database by making `domain_key` a plain column.
