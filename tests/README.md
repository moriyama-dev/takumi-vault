# Tests

Behaviour tests driven through WP-CLI against a real WordPress install. They
are not unit tests. Each case exercises the dangerous side of a branch against
a real database and a real web server, because that is where every bug found
so far has actually been: a gzip handle at the wrong position, a heartbeat that
looked like a lost lock, a row count that would have failed on any live site.

## Running

    tests/run-all.sh

Or one suite at a time:

    bin/deploy.sh
    cd /var/www/html && wp eval-file /path/to/tests/test-db-dump.php

`bin/deploy.sh` is not optional. The plugin cannot be symlinked from a home
directory the web server cannot traverse, and testing a stale copy is otherwise
one forgotten command away.

## They write to the database

Take a snapshot first:

    cd /var/www/html && wp db export ~/db-snapshots/$(date +%Y%m%d_%H%M%S).sql

`test-db-restore.php` restores the whole site database as part of its work.
Everything is put back, but a snapshot is the difference between a mistake and
an afternoon.

## Files

| File | Covers |
| --- | --- |
| `bootstrap.php` | Assertions, job driving, loopback blocking, gzip reading |
| `test-job-runner.php` | Chunking, hand-over, locking, the loopback endpoint's authentication, the watchdog, attempt limits |
| `test-db-dump.php` | Pagination per primary key shape, row-count verification, the manifest, awkward values, a cross-check against mysqldump |
| `test-db-restore.php` | The SQL parser, every refusal, the real round trip, the safety copy, undo, retention |

## Conventions

- Suites drive jobs from their own process and block the loopback, except
  where the loopback itself is what is being tested. On a development box the
  web server runs as a different user than WP-CLI, so a background worker
  cannot write into a `0700` backup directory and would race the test.
- Failure injection is registered through the `tkvault_job_handlers` filter,
  never added to the plugin. Code whose only purpose is to break does not ship.
- `tkv_single_chunk()` forces one chunk per request so a test can act between
  them.

Excluded from the distribution zip by `bin/build.sh`.
