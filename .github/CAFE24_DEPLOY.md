# Cafe24 GitHub Actions Deploy

`main` 브랜치에 push하면 GitHub Actions가 FTP로 `youngman-biz.com` 카페24 서버에 자동 업로드합니다.

## GitHub Secrets

GitHub 저장소에서 `Settings > Secrets and variables > Actions > New repository secret`에 아래 값을 추가하세요.

| Secret name | Value |
| --- | --- |
| `CAFE24_FTP_SERVER` | 카페24 FTP/SSH 주소 |
| `CAFE24_FTP_USER` | 카페24 FTP/SSH 아이디 |
| `CAFE24_FTP_PASSWORD` | 카페24 FTP/SSH 비밀번호 |
| `CAFE24_FTP_PORT` | `21` |

비밀번호는 저장소 파일에 직접 넣지 않습니다.

## Cafe24 Connection

| 항목 | 값 |
| --- | --- |
| FTP/SSH 주소 | GitHub Secret `CAFE24_FTP_SERVER` |
| FTP/SSH 아이디 | GitHub Secret `CAFE24_FTP_USER` |
| FTP 포트 | `21` |
| SSH 포트 | `22` |
| 업로드 경로 | `./` |

카페24 일반 웹호스팅 FTP는 로그인 직후 보이는 기본 위치가 웹 루트입니다. `server-dir`에 `/`, `/www/`, `/html/`, `/public_html/` 같은 절대경로를 지정하면 ProFTPD 보안 정책에 막힐 수 있으므로 상대경로 `./`로 업로드합니다.

워크플로는 `deploy/` 폴더를 임시로 만든 뒤 실제 서비스에 필요한 파일만 업로드합니다.

- `index.html`
- `style.css`
- `main.js`
- `Marketing.html`
- `kapp_premium.php`
- `lotto2233.html`
- `api/analyze.php` -> `analyze.php`
- `api/records.php` -> `records.php`

카페24 FTP에서 새 폴더 생성이 막힐 수 있어 PHP API는 서버 루트의 `analyze.php`, `records.php`로 배포합니다. `Marketing.html`도 같은 루트의 `analyze.php`를 호출합니다.

## Database

CRM/HRM 데이터는 카페24 MariaDB를 사용합니다. DB 비밀번호는 저장소에 커밋하지 않고, 카페24 서버 루트의 `db_config.php`에만 둡니다. 이 파일은 GitHub Actions 배포 대상에 포함하지 않습니다.

| 항목 | 값 |
| --- | --- |
| DB 주소 | `localhost` |
| DB 아이디 | 카페24 DB 아이디 |
| DB 포트 | `3306` |
| DB 종류 | `MariaDB` |

DB 비밀번호도 GitHub 저장소에 커밋하지 말고 Secret 또는 서버 전용 설정 파일로 관리하세요.

서버 루트의 `db_config.php` 형식:

```php
<?php
return [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'DB명',
    'user' => 'DB아이디',
    'password' => 'DB비밀번호',
];
```

## Supabase Auth

회원가입/로그인은 Supabase Auth를 사용합니다. 브라우저에는 공개 가능한 Supabase Project URL과 anon key만 둡니다.

로컬 또는 카페24 서버 루트의 `supabase_config.js` 형식:

```js
export const SUPABASE_URL = 'https://프로젝트-ref.supabase.co';
export const SUPABASE_ANON_KEY = 'Supabase anon public key';
```

DB API 보호를 위해 카페24 서버 루트의 `supabase_config.php`에 JWT secret을 둡니다. 이 파일은 GitHub Actions 배포 대상에 포함하지 않습니다.

```php
<?php
return [
    'jwt_secret' => 'Supabase JWT secret',
    'issuer' => 'https://프로젝트-ref.supabase.co/auth/v1',
    'audience' => 'authenticated',
    'require_auth' => true,
];
```

Supabase 값은 Dashboard > Project Settings > API에서 Project URL, anon public key, JWT secret을 확인해 입력하세요.

## Compatibility

워크플로는 기존 저장소에 `FTP_SERVER`, `FTP_USER`, `FTP_PASS`, `FTP_PASSWORD` 이름으로 Secret이 이미 등록된 경우도 함께 지원합니다. 새로 등록한다면 `CAFE24_` 접두어가 붙은 이름을 사용하세요.
