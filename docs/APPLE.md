# Apple

### Publish Apple root cert
```bash
php artisan vendor:publish --tag=apple-cert
```

Place the Apple Root CA G3 PEM in `storage/app/apple_root.pem`
