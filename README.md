# Git Satis for Monorepositories

Simple tool for generating **Composer repositories** from both **regular** and **monolithic** repositories. It requires HTTPS cloning which is compatible with Gitlab and Github.

Zip archives (dist packages) are created with commit hash in name so multiple revisions pointing to one commit will lead in one zip archive. If commit zip exists it won't be created again so if tool is run multiple times it will do incremental build.

## Installation

Download latest PHAR file from releases tab.

## Requirements

PHP: 7.2+, Linux or Mac OSX system

## Build repository

```
./git-satis.phar build build <repo-uri> <public-uri> [<out>]
./git-satis.phar build https://user:password@repo.com/repo.git https://my-satis-cdn.example.com
```

Build command will add all versions (tags, branches) to existing `out/` directory. It's possible to run this command multiple times to add many repositories to single Composer repository.

## Using repository in project

You have to add reference to repository in your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://my-satis-cdn.example.com/packages.json"
        }
    ]
}
```

If your repository i http-auth protected please consider creating `auth.json` file:

```json
{
    "http-basic": {
        "my-satis-cdn.example.com": {
            "username": "user",
            "password": "pass"
        },
    }
}
```