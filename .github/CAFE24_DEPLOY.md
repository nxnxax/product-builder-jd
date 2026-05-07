# Cafe24 GitHub Actions Deploy

`main` 브랜치에 push하면 GitHub Actions가 FTP로 `youngman-biz.com` 카페24 서버에 자동 업로드합니다.

## GitHub Secrets

GitHub 저장소에서 `Settings > Secrets and variables > Actions > New repository secret`에 아래 값을 추가하세요.

| Secret name | Value |
| --- | --- |
| `FTP_SERVER` | `youngman-biz.com` |
| `FTP_USER` | `nxnxqxx` |
| `FTP_PASS` | 카페24 FTP/SSH 계정 `nxnxqxx`의 비밀번호 |
| `FTP_PASSWORD` | 기존 설정과 호환하기 위한 FTP 비밀번호. `FTP_PASS`가 있으면 `FTP_PASS`를 우선 사용 |

비밀번호는 저장소 파일에 직접 넣지 않습니다.

## Cafe24 Connection

| 항목 | 값 |
| --- | --- |
| FTP/SSH 주소 | `youngman-biz.com` |
| FTP/SSH 아이디 | `nxnxqxx` |
| FTP 포트 | `21` |
| SSH 포트 | `22` |
| 업로드 경로 | `./` |

카페24 일반 웹호스팅 FTP는 로그인 직후 보이는 기본 위치가 웹 루트입니다. `server-dir`에 `/`, `/www/`, `/html/`, `/public_html/` 같은 절대경로를 지정하면 ProFTPD 보안 정책에 막힐 수 있으므로 상대경로 `./`로 업로드합니다.

워크플로는 `deploy/` 폴더를 임시로 만든 뒤 실제 서비스에 필요한 파일만 업로드합니다. 카페24 FTP에서 새 폴더 생성이 막힐 수 있어 PHP API는 서버 루트의 `analyze.php`로 배포합니다.

## Database

현재 자동배포에는 DB 접속 정보가 필요하지 않습니다. 앱에서 DB를 사용하게 되면 아래 정보를 기준으로 별도 서버 설정 파일이나 환경변수로 연결하세요.

| 항목 | 값 |
| --- | --- |
| DB 주소 | `localhost` |
| DB 아이디 | `nxnxqxx` |
| DB 포트 | `3306` |
| DB 종류 | `MariaDB` |

DB 비밀번호도 GitHub 저장소에 커밋하지 말고 Secret 또는 서버 전용 설정 파일로 관리하세요.
