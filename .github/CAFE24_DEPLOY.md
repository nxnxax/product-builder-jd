# Cafe24 GitHub Actions Deploy

`main` 브랜치에 push하면 GitHub Actions가 FTP로 `youngman-biz.com` 카페24 서버에 자동 업로드합니다.

## GitHub Secrets

GitHub 저장소에서 `Settings > Secrets and variables > Actions > New repository secret`에 아래 값을 추가하세요.

| Secret name | Value |
| --- | --- |
| `FTP_PASSWORD` | 카페24 FTP/SSH 계정 `nxnxqxx`의 비밀번호 |

비밀번호는 저장소 파일에 직접 넣지 않습니다.

## Cafe24 Connection

| 항목 | 값 |
| --- | --- |
| FTP/SSH 주소 | `youngman-biz.com` |
| FTP/SSH 아이디 | `nxnxqxx` |
| FTP 포트 | `21` |
| SSH 포트 | `22` |
| 업로드 경로 | `/www/` |

만약 카페24 FTP 접속 시 웹 루트가 이미 `www` 안으로 잡혀 있다면 `.github/workflows/deploy.yml`의 `server-dir`를 `/`로 바꾸세요.

## Database

현재 자동배포에는 DB 접속 정보가 필요하지 않습니다. 앱에서 DB를 사용하게 되면 아래 정보를 기준으로 별도 서버 설정 파일이나 환경변수로 연결하세요.

| 항목 | 값 |
| --- | --- |
| DB 주소 | `localhost` |
| DB 아이디 | `nxnxqxx` |
| DB 포트 | `3306` |
| DB 종류 | `MariaDB` |

DB 비밀번호도 GitHub 저장소에 커밋하지 말고 Secret 또는 서버 전용 설정 파일로 관리하세요.
