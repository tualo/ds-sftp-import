# ds-sftp-import

Requires PHP 8.1 or newer and phpseclib `^4.0.3` (installed via Composer).
The SFTP import route uses the `phpseclib4\Net\SFTP` API.

CSV configuration in `ds_import_file_transfer`: missing, null or empty `enclose`
uses the default double quote (`"`). Other enclosure values must contain exactly
one byte. `delimiter` must also contain exactly one byte after expanding `\t`,
`\r` and `\n`. Invalid values are reported in the route's `msg` result before
any CSV import mode is started.

The `use_load_file` mode requires `mysqli.allow_local_infile=On` in the PHP
configuration of the process running the import. If disabled or unavailable,
the import is aborted and the configuration error is returned in `msg`.
Other CSV import modes do not require this setting.
