- Export your host identity

```bash
export HOST_UID=$(id -u)
export HOST_GID=$(id -g)
```

- define docker function

```bash
dc() {
    docker compose -f compose.yaml -f compose.dev.yaml "$@"
}
```

- up command

```bash
dc up
```

- install composer dependencies

```bash
dc run --rm --no-deps php composer install
```
