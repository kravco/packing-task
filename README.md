### init
- `printf "UID=$(id -u)\nGID=$(id -g)" > .env`
- `docker-compose up -d`
- `docker-compose run shipmonk-packing-app bash`
- `composer install && vendor/bin/doctrine orm:schema-tool:create && vendor/bin/doctrine dbal:run-sql "$(cat data/packaging-data.sql)"`

### run
- ` export CREDENTIALS_USERNAME=your_3dbinpacking_username`
- ` export CREDENTIALS_API_KEY=your_3dbinpacking_api_key`
- `CREDENTIALS_USERNAME=$CREDENTIALS_USERNAME CREDENTIALS_API_KEY=$CREDENTIALS_API_KEY php run.php "$(cat sample.json)"`

### adminer
- Open `http://localhost:8080/?server=mysql&username=root&db=packing`
- Password: secret
