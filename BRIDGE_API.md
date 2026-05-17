# YOUNGMAN WebView Bridge API

웹(youngman.example) ↔ 네이티브 앱 (React Native WebView) 메시지 스펙.

웹 측 구현: `bridge.js`
앱 측 구현 예시: 본 문서 마지막 RN 스니펫.

---

## 1. 통신 채널

### 1-1. Web → App
모든 메시지는 단일 채널 `window.ReactNativeWebView.postMessage` 로 전송되는 JSON 문자열입니다.

```ts
type WebToAppMessage = {
  type: string;        // 메시지 타입 (아래 표)
  payload: any;        // null 가능
  ts: number;          // Date.now()
};
```

### 1-2. App → Web
앱은 WebView 의 `injectJavaScript` 를 사용해 다음 인터페이스를 호출합니다.

```js
window.YoungmanBridge.handle(name, ...args);    // 범용 dispatch
window.YoungmanBridge.onReady(info);            // 핸드셰이크
window.YoungmanBridge.onAppInfo(info);
window.YoungmanBridge.onFcmToken(token);
window.YoungmanBridge.onPushOpen(data);
window.YoungmanBridge.onAppResume();
window.YoungmanBridge.onBack();                 // boolean 반환
```

`window.YoungmanBridge` 는 `bridge.js` 로드 후 항상 존재합니다. 페이지 전환 직후
inject 가 늦으면 잠시 미존재할 수 있으므로 `bridge.ready` 메시지를 받은 뒤 inject 하면
안전합니다.

---

## 2. Web → App 메시지 타입

| type | payload | 발생 시점 |
|---|---|---|
| `bridge.ready` | `{ version, page, userAgent }` | 페이지 로드 직후 (브리지 초기화) |
| `auth.login` | `{ accessToken, refreshToken, userId, email, expiresAt }` | 로그인 성공 / 세션 갱신 / 페이지 진입 시 기존 세션 |
| `auth.logout` | `null` | 로그아웃 (logout.html 진입 시) |
| `auth.googleSignIn.request` | `{ nonce }` | 앱 안에서 Google 로그인 버튼 클릭 — 앱이 Google SDK 호출해야 함 |
| `nav.openExternal` | `{ url }` | 외부 도메인 또는 `target="_blank"` 링크 클릭 |
| `nav.share` | `{ title?, text?, url? }` | 웹에서 share API 호출 |
| `app.info.request` | `null` | 웹이 앱 정보 요청 |
| `app.fcm.request` | `null` | 웹이 FCM 토큰 요청 |
| `app.statusBar` | `{ style: 'light'\|'dark', color?: string }` | 상태바 스타일 변경 요청 |
| `app.haptic` | `{ type: 'light'\|'success'\|'warning'\|'error' }` | 햅틱 피드백 요청 |

### auth.login payload 예시
```json
{
  "type": "auth.login",
  "payload": {
    "accessToken": "eyJhbGciOi...",
    "refreshToken": "abc123...",
    "userId": "uuid-v4",
    "email": "user@example.com",
    "expiresAt": 1747476000
  },
  "ts": 1715000000000
}
```

앱은 `accessToken` 을 Bearer 토큰으로 직접 호출(records.php 등)하거나, FCM 토큰 매핑
저장 시 사용자 식별자(`userId` / `email`)로 사용.

---

## 3. App → Web 핸들러

### 3-1. onReady(info)
앱이 페이지 로드를 확인하고 자기소개를 보내는 핸드셰이크. `bridge.ready` 수신 후 호출 권장.

```js
window.YoungmanBridge.onReady({
  platform: 'android',         // 'android' | 'ios'
  appVersion: '1.0.0',
  osVersion: '14',
  deviceModel: 'Pixel 8',
  fcmToken: 'fcm-token-here',  // 있으면 같이
});
```

### 3-2. onFcmToken(token)
FCM 토큰을 단독 전달할 때 (앱 시작 후 비동기로 받았을 때).

```js
window.YoungmanBridge.onFcmToken('fcm-token-here');
```

### 3-3. onPushOpen(data)
유저가 푸시 알림 탭으로 앱을 진입했을 때.

```js
window.YoungmanBridge.onPushOpen({ route: '/customers', recordId: 123 });
```

웹은 `data.route` 가 있으면 `location.assign(data.route)` 같은 식으로 처리할 수 있습니다.

### 3-4. onAppResume()
백그라운드 → 포어그라운드 복귀. 웹은 세션 만료 체크/재인증 트리거에 사용.

### 3-5. onGoogleSignInResult(result)
앱이 Google Sign-In SDK 호출 결과를 웹에 반환. 웹이 `auth.googleSignIn.request` 를
보낸 뒤 응답으로 호출.

```js
window.YoungmanBridge.handle('onGoogleSignInResult', {
  idToken: 'eyJhbGciOi...',   // Google ID token (필수)
  accessToken: null,          // 선택 — 필요 없음
  email: 'user@gmail.com',    // 선택
  // 또는 실패/취소:
  // error: 'NETWORK_ERROR',
  // cancelled: true,
});
```

웹이 받은 idToken 으로 `supabase.auth.signInWithIdToken({ provider:'google', token, nonce })`
를 호출 — 백엔드 검증 엔드포인트 별도 불필요. 성공 시 기존 `auth.login` 메시지 흐름으로 자연 연결.

**nonce 처리 (replay 공격 방지):**
1. 웹이 raw nonce 생성 → SHA-256 hash 만 `auth.googleSignIn.request` 의 `nonce` 로 전달
2. 앱은 그 hash 를 Google SDK 의 nonce 파라미터로 그대로 사용
3. 결과 idToken 안에 hash 가 포함됨 — Supabase 가 raw nonce 를 hash 해서 비교
4. 앱은 raw nonce 를 모름. hash 만 알아도 됨.

### 3-6. onBack() → boolean
Android 뒤로가기 버튼. 웹이 모달/드로어/시트를 닫았으면 `true`, 아니면 `false` 반환.
앱은 `false` 일 때 라우터 pop 또는 앱 종료를 진행.

```js
const handled = window.YoungmanBridge.onBack(); // true | false
```

기본 핸들러는 `.modal.is-open`, `.drawer.is-open`, `.sheet.is-open`, `[data-modal-open="true"]`
중 하나가 있으면 닫고 `true`. 페이지별로 추가 동작 필요하면:

```js
import bridge from './bridge.js';
bridge.setHandler('onBack', () => {
  if (myCustomOverlayOpen) { closeMyOverlay(); return true; }
  return false;
});
```

---

## 4. RN 앱 측 구현 스니펫

```jsx
import React, { useRef, useCallback } from 'react';
import { BackHandler, Linking, Share, Platform } from 'react-native';
import { WebView } from 'react-native-webview';
import messaging from '@react-native-firebase/messaging';

const WEB_URL = 'https://youngman.example/index.html';

export default function App() {
  const webRef = useRef(null);

  const inject = useCallback((js) => {
    webRef.current?.injectJavaScript(js + '; true;');
  }, []);

  const sendHandshake = useCallback(async () => {
    const fcmToken = await messaging().getToken().catch(() => null);
    inject(`window.YoungmanBridge && window.YoungmanBridge.onReady(${JSON.stringify({
      platform: Platform.OS,
      appVersion: '1.0.0',
      osVersion: String(Platform.Version),
      fcmToken,
    })})`);
  }, [inject]);

  const onMessage = useCallback(async (e) => {
    let msg;
    try { msg = JSON.parse(e.nativeEvent.data); } catch { return; }
    const { type, payload } = msg;

    switch (type) {
      case 'bridge.ready':
        sendHandshake();
        break;

      case 'auth.login':
        // payload.accessToken 을 SecureStore 등에 저장
        // payload.userId 와 FCM 토큰을 서버에 매핑 (records.php 또는 별도 endpoint)
        break;

      case 'auth.logout':
        // 저장된 토큰/세션 wipe
        break;

      case 'nav.openExternal':
        Linking.openURL(payload.url).catch(() => {});
        break;

      case 'nav.share':
        Share.share({
          title: payload.title,
          message: payload.text || payload.url,
          url: payload.url,
        }).catch(() => {});
        break;

      case 'app.fcm.request': {
        const token = await messaging().getToken().catch(() => null);
        if (token) inject(`window.YoungmanBridge.onFcmToken(${JSON.stringify(token)})`);
        break;
      }

      case 'auth.googleSignIn.request': {
        // payload.nonce 는 SHA-256 hash — Google SDK 에 그대로 전달.
        // import { GoogleSignin } from '@react-native-google-signin/google-signin';
        try {
          // 앱 초기화에서: GoogleSignin.configure({ webClientId: '<YOUR_WEB_CLIENT_ID>' });
          await GoogleSignin.hasPlayServices();
          // RN Google Sign-In v11+ 는 signIn 옵션에 nonce 지원.
          const userInfo = await GoogleSignin.signIn({ nonce: payload.nonce });
          const idToken = userInfo?.idToken || userInfo?.data?.idToken;
          inject(`window.YoungmanBridge.handle('onGoogleSignInResult', ${JSON.stringify({
            idToken,
            email: userInfo?.user?.email || userInfo?.data?.user?.email || null,
          })})`);
        } catch (e) {
          const cancelled = e?.code === 'SIGN_IN_CANCELLED' || e?.message?.includes('cancel');
          inject(`window.YoungmanBridge.handle('onGoogleSignInResult', ${JSON.stringify({
            cancelled: !!cancelled,
            error: cancelled ? null : String(e?.message || e),
          })})`);
        }
        break;
      }

      case 'app.info.request':
        inject(`window.YoungmanBridge.onAppInfo(${JSON.stringify({
          platform: Platform.OS,
          appVersion: '1.0.0',
          osVersion: String(Platform.Version),
        })})`);
        break;

      case 'app.statusBar':
        // StatusBar.setBarStyle(payload.style === 'dark' ? 'dark-content' : 'light-content');
        break;

      case 'app.haptic':
        // react-native-haptic-feedback 등으로 처리
        break;
    }
  }, [inject, sendHandshake]);

  // Android 뒤로가기 — 웹에 위임, 처리 안 했으면 앱 동작
  React.useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      // 동기 평가 — onBack 은 boolean 반환
      let handled = false;
      webRef.current?.injectJavaScript(`
        (function() {
          var r = false;
          try { r = !!(window.YoungmanBridge && window.YoungmanBridge.onBack()); } catch(e) {}
          window.ReactNativeWebView.postMessage(JSON.stringify({
            type: '__back.result', payload: { handled: r }, ts: Date.now(),
          }));
        })(); true;
      `);
      // back 처리는 비동기 메시지 응답으로 받아 외부 state 로 관리하는 패턴이 안전.
      // 단순화하려면 onBack 호출 후 항상 true 반환하고 종료는 별도 UI 로.
      return true;
    });
    return () => sub.remove();
  }, []);

  // 포어그라운드 복귀 알림
  React.useEffect(() => {
    const sub = require('react-native').AppState.addEventListener('change', (s) => {
      if (s === 'active') inject(`window.YoungmanBridge && window.YoungmanBridge.onAppResume()`);
    });
    return () => sub.remove();
  }, [inject]);

  return (
    <WebView
      ref={webRef}
      source={{ uri: WEB_URL }}
      onMessage={onMessage}
      javaScriptEnabled
      domStorageEnabled
      sharedCookiesEnabled
      thirdPartyCookiesEnabled
      originWhitelist={['*']}
      // 외부 링크는 웹이 nav.openExternal 메시지로 전달하므로 여기서 차단:
      onShouldStartLoadWithRequest={(req) => {
        // 같은 도메인만 허용
        return req.url.startsWith(WEB_URL.split('/').slice(0, 3).join('/'));
      }}
    />
  );
}
```

---

## 5. 보안 / 운영 노트

- `accessToken` 은 SecureStore / EncryptedSharedPreferences 등 안전한 저장소에 보관.
  AsyncStorage 평문 저장 금지.
- `auth.login` 은 토큰 갱신(`TOKEN_REFRESHED`) 마다도 발화 — 앱은 항상 최신 값으로 덮어쓰면 됨.
- FCM 토큰은 만료/회전됨. `messaging().onTokenRefresh` 도 구독해 `onFcmToken(newToken)` 으로 갱신.
- 웹은 브라우저(앱 외부)에서도 동작해야 하므로 모든 `postToApp` 는 no-op fallback 보장됨.
  앱쪽도 `bridge.ready` 미수신을 정상 케이스로 처리(즉, 외부 브라우저 사용자).
- WebView 의 `originWhitelist` 와 `onShouldStartLoadWithRequest` 로 외부 도메인 진입 차단,
  외부 링크는 `nav.openExternal` 메시지를 통해 시스템 브라우저로만 열기.

---

## 6. 버전

- bridge.js v1.0.0 (2026-05-17)
- 메시지 추가 시 본 문서 표 갱신 후 `bridge.js` 의 `BRIDGE_VERSION` bump.
