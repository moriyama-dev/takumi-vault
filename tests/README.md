# Tests

Behaviour tests driven through WP-CLI against a real WordPress install. They
are not unit tests: each one exercises the dangerous side of a branch against a
real database, because that is where every bug found so far has been.

    bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-db-restore.php

They write to the database. Take a snapshot first:

    cd /var/www/html && wp db export ~/db-snapshots/$(date +%Y%m%d_%H%M%S).sql

Excluded from the distribution zip by bin/build.sh.

## Missing suites

The step 2 (job runner) and step 3 (database dump) suites were written in a
scratch directory and lost with it. They need rewriting here. What they
covered is recorded in the design document's progress section.
