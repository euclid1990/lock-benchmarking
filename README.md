# Lock Benchmarking

This repository contains lock implementations, delegation lock implementations and a set of benchmarks.

## Init

```bash
$ docker-compose exec php /bin/bash
$ docker-compose exec php /bin/bash -c "php command.php init --seed --refresh"
$ docker-compose exec php /bin/bash -c "php command.php init --seed --booking --refresh"    # Seed booking placeholders
```

## Access

- Web
```bash
http://localhost:8000
```
- PhpMyadmin
```bash
http://localhost:8001
```

## Testing

- cURL
```bash
$ curl -X POST http://localhost:8000/create -H 'Content-Type: application/json' --data @benchmark/data.json
```
