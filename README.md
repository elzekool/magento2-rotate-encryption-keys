# Magento Encryption Key Rotation Tool

## Thank you!
This repository is a fork of https://github.com/bemeir/magento2-rotate-encryption-keys. I want to thank the original authors for creating this script. 

This is a slightly modified version that has a few differences:
* It removes the CSV export functionality
* It always read the key from env.php (also the new key)
* It reads the ID field from the database
* It can generate a list of commands from the original scan command
* Allows running in a sub-folder (for Magento Cloud/Read only file systems)

## Overview

This script addresses the limitations of Magento's native encryption key rotation functionality, particularly in light of recent security vulnerabilities like CosmicSting. It provides a different approach to re-encrypting sensitive data across Magento databases.

This script was built to aid with https://sansec.io/research/cosmicsting-hitting-major-stores and for merchants facing issues using the Adobe supplied tool.

From the sansec post
> Upgrading is Insufficient
> As we warned in our earlier article, it is crucial for merchants to upgrade or apply the official isolated fix. At this stage however, just patching for the CosmicSting vulnerability is likely to be insufficient.
>
>The stolen encryption key still allows attackers to generate web tokens even after upgrading. Merchants that are currently still vulnerable should consider their encryption key as compromised. Adobe offers functionality out of the box to change the encryption key while also re-encrypting existing secrets.
>
>Important note: generating a new encryption key using this functionality does not invalidate the old key. We recommend manually updating the old key in app/etc/env.php to a new value rather than removing it.

## Disclaimer
This tool is provided as-is, without any warranty. Use at your own risk and always test thoroughly in a non-production environment first.

## Features

- Scans all database tables for encrypted values
- Re-encrypts data using a new encryption key
- Handles core Magento tables and custom third-party extension tables
- Supports multiple encryption keys
- Generates backup of current encrypted values
- Option to update database directly or generate SQL update statements

## Installation

1. Clone this repository or download the `update-encryption.php` script.
2. Place the script in the root directory of your Magento installation.

## Functionality

### Scan Mode

Run the script in scan mode to identify encrypted values.
On execution it shows the results in the following format:

`<tablename>::<field>::<id_field>`

```
php update-encryption.php scan [--old-key-number=NUMBER]
```

Example output:
```
core_config_data::value::config_id
oauth_consumer::secret::entity_id
oauth_token::secret::entity_id
tfa_user_config::encoded_config::config_id
```

### Generate Commands Mode

Run the script in generate-command mode to scan the database for encrypted
values and generate the appropiate update-table commands.

```
php update-encryption.php generate-commands [--key-number=NUMBER] [--old-key-number=NUMBER] [--dry-run] [--dump-file=FILENAME] [--backup-file=FILENAME]
```

Example output:
```
php update-encryption.php update-table --table=core_config_data --field=value --id-field=config_id
php update-encryption.php update-table --table=oauth_consumer --field=secret --id-field=entity_id
php update-encryption.php update-table --table=oauth_token --field=secret --id-field=entity_id
php update-encryption.php update-table --table=tfa_user_config --field=encoded_config --id-field=config_id
```

For the parameters of this mode see Update Table Values mode. Any parameter given will be added to the 
generated commands.

### Update Table Values Mode

Run the script in the update-tables mode to update the values in the database or echo/dump the commands to
the console and/or SQL files.

```
php update-encryption.php update-table --table=TABLE --field=FIELD --id-field=ID_FIELD [--key-number=NUMBER] [--old-key-number=NUMBER] [--dry-run] [--dump-file=FILENAME] [--backup-file=FILENAME]
```

Example output:
```
UPDATE `core_config_data` SET `value`='0:3:**REDACTED**' WHERE `config_id`=313 LIMIT 1;
UPDATE `core_config_data` SET `value`='0:3:**REDACTED**' WHERE `config_id`=242 LIMIT 1;
```

It supports the following arguments:

* `--table=TABLE` Table name to update
* `--field=FIELD` Field name to update
* `--id-field=ID_FIELD` Field to use as unique identifier
* `--key-number=NUMBER` (optional) key number to use for encryption (default = 1, e.g. second crypt key)
* `--old-key-number=NUMBER` (optional) key number to use for decryption (default = 0, e.g. first crypt key)
* `--dru-run` (optional) if flag is added no SQL queries are executed
* `--dump-file=FILENAME` (optional) if file is given queries are dumped (added) to this file instead of executing. 
* `--backup-file=FILENAME` (optional) if file is given queries are dumped (added) to this file to revert the database changes.

### Update Single Record Values Mode

This mode is exactly the same as the Update Table Values Mode but with the addition of 1 parameter:

* `--id=ID` only update this id


## Suggested usage

### Step 1
Put the environment into maintenance mode.

### Step 2
Make a full backup of the database

## Step 3
Add an additional crypt key to `env.php`. This additional key needs to be `SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES` long which in general is 32 characters. Important! The value is a single string seperated by a whitespace (enter or space), e.g. `key1 key2`

## Step 4
Run the command `php update-encryption.php generate-commands --backup-file=restore-values.sql`

## Step 5
Run the commands outputted by the previous command. 

## Step 6
Flush the cache


## Important Notes

- This script is designed for use by experienced Magento developers.
- Always backup your database before running this script.
- The script uses `fetchAll`, which may consume significant memory for large tables.
- Currently only supports Sodium for encryption (legacy mcrypt values are not handled).
- Encrypted values within JSON or URL parameters may be missed.

## Caution

- Do not attempt to decrypt or re-encrypt hashed passwords.
- Be cautious when dealing with payment information and other sensitive data.
- Always make a backup before using this command
- Make sure you clean up any file generated by this tool

## Limitations

- May not catch all encrypted values, especially those embedded in complex data structures.
- Performance may be impacted on very large databases.

## Alternative Solutions

For those preferring a Magento module-based approach, consider:
[Gene Commerce Encryption Key Manager](https://github.com/genecommerce/module-encryption-key-manager/)

And also look at the original variant of this script:
[bemeir/magento2-rotate-encryption-keys](https://github.com/bemeir/magento2-rotate-encryption-keys)

## License

MIT License

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
