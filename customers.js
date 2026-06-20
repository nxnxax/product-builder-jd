/**
 * customers.js — 고객 관리대장 (Phase 4)
 *
 * page_type='customer'. Phase 1 ledger 시스템 위에서 동작.
 * 가장 단순한 페이지 — 수수료 계산이나 팀 분리 같은 복잡 로직 없음.
 *
 * 모바일 앱 연동 사전 준비:
 *  - source 컬럼 'web' 외에 'mobile-call', 'mobile-ocr' 같이 출처 구분
 *  - client_idempotency_key 로 같은 통화의 중복 전송 차단
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260619-gp';
import { attachColumnFilters, applyColumnFilters, openRowAddModal, attachPhoneAutoFormat, getEffectiveFields, mountFieldManager,
         exportRecordsToExcel, pickExcelFile, parseExcelFile, suggestFieldMapping, openImportPreviewModal,
         saveImportSession, loadImportSession, clearImportSession,
         findBlankRecordIds, showSweepToast,
         attachCellClickHandlers,
         isLedgerMobile, onLedgerViewportChange,
         deepSearchMatch, normalizeSearchQuery } from './ledger-shared.js?v=20260520-search-deep';

const MOBILE_PRIMARY_KEYS = ['customer', 'phone', 'date'];

const PAGE_TYPE = 'customer';

const DEFAULT_FIELDS = [
    { key: 'no',         label: 'NO',          type: 'auto_number',  filterable: false, width: 44 },
    { key: 'managed',    label: '관리',        type: 'manage_switch',filterable: true,  width: 76 },
    { key: 'date',       label: '날짜',        type: 'date',         filterable: true,  width: 78 },
    { key: 'call_count', label: '통화수',      type: 'call_count',   filterable: true,  width: 58 },
    { key: 'customer',   label: '고객명',      type: 'text',         filterable: true,  width: 96 },
    { key: 'phone',      label: '연락처',      type: 'tel',          filterable: false, width: 116 },
    { key: 'region',     label: '지역',        type: 'text',         filterable: true,  width: 84 },
    { key: 'content',    label: '내용',        type: 'textarea',     filterable: true,  width: 240 },
    { key: 'agent_memo', label: '담당자 메모', type: 'textarea',     filterable: true,  width: 160 },
    { key: 'memo',       label: '비고',        type: 'text',         filterable: false, width: 100 },
];

// 엑셀 헤더 → 우리 필드 매칭용 한국어 동의어 사전. 매핑 안 되는 컬럼은 fallbackKey 로 합쳐짐.
const FIELD_SYNONYMS = {
    managed:    ['관리', '관리상태', '관리여부'],
    date:       ['날짜', '일자', '등록일', '상담일', '통화일', '접수일', '문의일'],
    customer:   ['고객명', '고객', '성명', '이름', '의뢰인', '문의자'],
    phone:      ['연락처', '휴대폰', '휴대폰번호', '핸드폰', '핸드폰번호', '전화번호', '전화', '모바일', 'HP', 'tel', 'phone', '번호'],
    call_count: ['통화수', '통화횟수', '연락횟수', '회차', '몇번째'],
    region:     ['거주지역', '거주지', '주소', '지역', '사는곳', 'address'],
    content:    ['내용', '상담내용', '통화내용', '상담', '문의내용', '메모내용'],
    agent_memo: ['담당자메모', '담당자 메모', '영업메모', '상담자메모', 'agent_memo'],
    memo:       ['비고', '메모', '특이사항', '참고', '기타', 'note', 'remarks'],
};
const FALLBACK_FIELD_KEY = 'content';

/* ============== State ============== */
let supabaseClient = null;
let groups = [];
let expandedGroupIds = new Set();   // 그룹 생성/삭제 추적용 (UI 토글 X)
let selectedExtraIds = new Set();   // 기본 그룹 외에 추가로 표시 중인 그룹들
let extraPanelOpen = false;         // "다른 그룹" picker 열림 여부
let records = [];
let editingGroupId = null;
let filterState = { filters: {} };
let selectedIds = new Set();
let searchByGroup = {};   // groupId → 검색어 (그룹 카드 내부 검색창)
let managedOnlyByGroup = {};  // groupId → true 면 '관리중' 항목만 표시 (모바일 전용. PC 는 컬럼 필터 사용)

/* ============== Boot ============== */
(async function boot() {
    try {
        const result = await initSupabase();
        supabaseClient = result?.client || null;
    } catch (e) { supabaseClient = null; }

    // 광고용 데모 화면: customers.html?demo=1 이면 인증/서버 대신 예시 고객 20명을 주입한다.
    // 실제 고객 데이터/DB 와 전혀 무관(PII 아님). 캡쳐 용도. 정상 사용자 흐름엔 영향 없음.
    if (/[?&]demo=1\b/.test(location.search)) {
        bindUI();
        loadDemoData();
        onLedgerViewportChange(() => renderRecords());
        return;
    }

    const session = getSession();
    if (!supabaseClient || !session) {
        document.getElementById('content').innerHTML =
            `<div class="empty">로그인이 필요합니다. <a href="index.html" style="color:var(--ledger-accent);font-weight:600;">홈으로 가서 로그인</a></div>`;
        return;
    }
    bindUI();
    await loadGroups();
    onLedgerViewportChange(() => renderRecords());
})();

/* ============== 광고용 데모 데이터 (예시 고객 20명) ============== */
function loadDemoData() {
    groups = [
        { id: 1, name: '서울 아파트 분양', isDefault: true },
        { id: 2, name: '수도권 아파트 상담', isDefault: false },
    ];
    expandedGroupIds = new Set([1]);
    const rows = [
        { managed:1, date:'2026-06-16', call_count:3, customer:'김도윤', phone:'010-0000-0001', region:'수원 영통구',
          content:'📞 2026-06-16 14:22 통화 (3회차)\n[계약임박] 84A 남향 계약 의사 — 자금 확인 완료\n• 84A 남향 선호, 예산 4억 후반 확보\n• 중도금 무이자·발코니 확장 조건 재확인\n• 주말 모델하우스 재방문 약속\nAI 의견: 계약 성사 가능성 매우 높음. 가용 동호수 우선 안내 권장.',
          agent_memo:'토요일 11시 재방문, VIP 응대', memo:'가망 A' },
        { managed:1, date:'2026-06-16', call_count:2, customer:'이서연', phone:'010-0000-0002', region:'화성 동탄',
          content:'📞 2026-06-16 11:05 통화 (2회차)\n[관심] 59㎡ 투자 문의 — 전세가율·임대수요 질문\n• 59B 선호, 갭투자 목적\n• 입주장 전세 시세·임대 수요 자료 요청\nAI 의견: 투자 정보 제공 시 전환 가능. 임대수익 시뮬레이션 발송 권장.',
          agent_memo:'임대수익 자료 메일 발송함', memo:'투자' },
        { managed:1, date:'2026-06-15', call_count:5, customer:'박준혁', phone:'010-0000-0003', region:'용인 기흥',
          content:'📞 2026-06-15 16:40 통화 (5회차)\n[계약완료] 102동 1504호 정계약 체결\n• 계약금 납부 완료, 중도금 대출 안내 마침\n• 잔금 일정·입주 지정일 협의 완료\nAI 의견: 마무리 단계. 입주 지원 서비스 안내로 만족도 관리.',
          agent_memo:'계약 완료 ✅ 입주안내문 발송', memo:'계약' },
        { managed:1, date:'2026-06-15', call_count:1, customer:'최지우', phone:'010-0000-0004', region:'성남 분당',
          content:'📞 2026-06-15 10:18 통화 (1회차)\n[신규] 84B 분양가·옵션 첫 문의\n• 분양가표·옵션 품목 요청\n• 자녀 학군 정보 함께 문의\nAI 의견: 학군 자료 동봉해 회신 시 재상담 유도 가능.',
          agent_memo:'분양가표+학군지도 발송', memo:'신규' },
        { managed:0, date:'2026-06-14', call_count:1, customer:'정민서', phone:'010-0000-0005', region:'수원 권선구',
          content:'📞 2026-06-14 13:55 통화 (1회차)\n[보류] 예산 부족으로 당분간 보류\n• 희망 예산 3억 초반, 분양가와 차이 큼\n• 추후 미분양 할인 시 재연락 희망\nAI 의견: 단기 전환 어려움. 분기별 안부 연락 대상으로 관리.',
          agent_memo:'3개월 뒤 재연락', memo:'장기' },
        { managed:1, date:'2026-06-14', call_count:2, customer:'강하윤', phone:'010-0000-0006', region:'화성 봉담',
          content:'📞 2026-06-14 09:30 통화 (2회차)\n[관심] 중도금 무이자 조건 재확인\n• 84A 관심, 무이자 횟수·시점 질문\n• 사은품·이사지원 여부 문의\nAI 의견: 조건 명확화로 신뢰 형성. 계약 상담 일정 제안 권장.',
          agent_memo:'금요일 상담 일정 조율 중', memo:'가망 B' },
        { managed:1, date:'2026-06-13', call_count:4, customer:'윤서준', phone:'010-0000-0007', region:'오산 세교',
          content:'📞 2026-06-13 15:12 통화 (4회차)\n[계약임박] 청약 당첨 — 계약서류 안내\n• 당첨 동호수 확인, 계약일 예약\n• 필요 서류·계약금 비율 안내\nAI 의견: 계약 확정적. 서류 체크리스트 문자 발송으로 이탈 방지.',
          agent_memo:'계약 6/20 14시 예약', memo:'당첨' },
        { managed:1, date:'2026-06-13', call_count:1, customer:'임채원', phone:'010-0000-0008', region:'수원 장안구',
          content:'📞 2026-06-13 11:48 통화 (1회차)\n[신규] 발코니 확장·시스템에어컨 옵션 문의\n• 84C 관심, 확장 시 평면 변화 질문\n• 옵션 일괄 계약 시 할인 여부\nAI 의견: 옵션 카탈로그 발송 후 재상담 약속 권장.',
          agent_memo:'옵션 카탈로그 발송', memo:'신규' },
        { managed:1, date:'2026-06-12', call_count:3, customer:'한지호', phone:'010-0000-0009', region:'평택 고덕',
          content:'📞 2026-06-12 17:02 통화 (3회차)\n[관심] 직주근접 실거주 — 입주시기 조율\n• 직장(대기업 캠퍼스) 출퇴근 고려\n• 입주 지정일·전입 시기 상담\nAI 의견: 실거주 니즈 뚜렷. 입주 일정 맞춤 안내로 계약 견인.',
          agent_memo:'입주 일정표 전달', memo:'실거주' },
        { managed:1, date:'2026-06-12', call_count:2, customer:'오유진', phone:'010-0000-0010', region:'용인 처인구',
          content:'📞 2026-06-12 10:40 통화 (2회차)\n[관심] 84A vs 84B 평면 비교 상담\n• 주방 동선·수납 차이 질문\n• 남향/판상형 선호\nAI 의견: 평면 비교표 제공 시 결정 빨라질 것. 방문 유도 권장.',
          agent_memo:'평면 비교표 발송, 방문 권유', memo:'가망 B' },
        { managed:0, date:'2026-06-11', call_count:1, customer:'서지안', phone:'010-0000-0011', region:'안산 단원구',
          content:'📞 2026-06-11 14:20 통화 (1회차)\n[부재] 부재중 — 문자 안내 발송\n• 통화 연결 안 됨\n• 분양 안내 문자 + 모델하우스 위치 전송\nAI 의견: 재시도 필요. 저녁 시간대 통화 재시도 권장.',
          agent_memo:'저녁에 재시도', memo:'부재' },
        { managed:1, date:'2026-06-11', call_count:3, customer:'신우빈', phone:'010-0000-0012', region:'수원 팔달구',
          content:'📞 2026-06-11 09:10 통화 (3회차)\n[계약임박] 부모 증여 자금 확정 — 계약 결심\n• 증여 절차·자금 출처 정리 완료\n• 84A 계약 의사 확정\nAI 의견: 자금 준비 완료. 계약일 즉시 확정 권장.',
          agent_memo:'계약일 잡기로 함', memo:'가망 A' },
        { managed:1, date:'2026-06-10', call_count:1, customer:'곽민재', phone:'010-0000-0013', region:'화성 향남',
          content:'📞 2026-06-10 16:33 통화 (1회차)\n[신규] 분양 일정·청약 자격 문의\n• 1순위 자격 요건 질문\n• 특별공급 해당 여부 확인 요청\nAI 의견: 자격 안내 후 청약 독려. 일정 알림 등록 권장.',
          agent_memo:'청약 일정 알림 등록', memo:'신규' },
        { managed:1, date:'2026-06-10', call_count:2, customer:'배수아', phone:'010-0000-0014', region:'성남 수정구',
          content:'📞 2026-06-10 11:25 통화 (2회차)\n[관심] 1층·저층 세대 프라이버시 문의\n• 어린 자녀로 저층 선호\n• 1층 테라스·필로티 여부 질문\nAI 의견: 저층 매물 안내로 맞춤 제안. 방문 상담 연결 권장.',
          agent_memo:'저층 동호수 안내', memo:'가망 B' },
        { managed:1, date:'2026-06-09', call_count:4, customer:'문지환', phone:'010-0000-0015', region:'수원 영통구',
          content:'📞 2026-06-09 15:50 통화 (4회차)\n[계약완료] 105동 802호 계약 체결\n• 계약금 입금 확인\n• 중도금 대출 은행 안내 완료\nAI 의견: 계약 완료. 입주 예약 서비스 연계로 추가 만족도.',
          agent_memo:'계약 완료 ✅', memo:'계약' },
        { managed:1, date:'2026-06-09', call_count:1, customer:'노하은', phone:'010-0000-0016', region:'용인 수지구',
          content:'📞 2026-06-09 10:05 통화 (1회차)\n[신규] 모델하우스 방문 예약 요청\n• 주말 가족 방문 희망\n• 주차·관람 소요시간 문의\nAI 의견: 방문 예약 확정. 방문 전 리마인드 문자 권장.',
          agent_memo:'토요일 14시 방문 예약', memo:'방문예약' },
        { managed:1, date:'2026-06-08', call_count:2, customer:'천예준', phone:'010-0000-0017', region:'안성 공도',
          content:'📞 2026-06-08 13:18 통화 (2회차)\n[관심] 분양가 협의·할인 가능 여부\n• 분양가 부담, 할인·혜택 질문\n• 발코니 확장 무상 여부 확인\nAI 의견: 혜택 패키지 안내로 가격 저항 완화. 재상담 약속 권장.',
          agent_memo:'혜택 패키지 안내함', memo:'가망 B' },
        { managed:0, date:'2026-06-08', call_count:1, customer:'구시현', phone:'010-0000-0018', region:'수원 권선구',
          content:'📞 2026-06-08 09:42 통화 (1회차)\n[보류] 타 단지와 비교 중 — 결정 보류\n• 인근 신축과 입지·가격 비교 중\n• 2주 뒤 재연락 희망\nAI 의견: 경쟁 단지 대비 강점(역세권·학군) 정리해 재접촉 권장.',
          agent_memo:'2주 뒤 비교자료로 재연락', memo:'경쟁' },
        { managed:1, date:'2026-06-07', call_count:3, customer:'황도경', phone:'010-0000-0019', region:'화성 동탄',
          content:'📞 2026-06-07 16:55 통화 (3회차)\n[계약임박] 부부 동반 방문 후 84A 결심\n• 부부 모두 만족, 자금 계획 확정\n• 계약 가능일 문의\nAI 의견: 결심 단계. 계약일 즉시 예약 권장.',
          agent_memo:'계약일 조율 중', memo:'가망 A' },
        { managed:1, date:'2026-06-07', call_count:1, customer:'양서윤', phone:'010-0000-0020', region:'평택 비전동',
          content:'📞 2026-06-07 11:30 통화 (1회차)\n[신규] 전매·입주 시점 문의\n• 입주까지 기간·전매 제한 질문\n• 실입주 vs 전세 고민\nAI 의견: 전매 규정 안내 후 실거주 장점 강조. 재상담 유도.',
          agent_memo:'전매 규정 안내', memo:'신규' },
        { managed:1, date:'2026-06-06', call_count:2, customer:'김하준', phone:'010-0000-0021', region:'수원 영통구',
          content:'📞 2026-06-06 15:40 통화 (2회차)\n[관심] 84A 향·층 조망 재확인\n• 고층 남향 선호, 조망권 질문\n• 발코니 확장 시 거실 채광 문의\nAI 의견: 조망 자료 제공 시 결정 가속. 방문 상담 권장.',
          agent_memo:'고층 조망 사진 발송', memo:'가망 B' },
        { managed:1, date:'2026-06-06', call_count:1, customer:'이도현', phone:'010-0000-0022', region:'화성 동탄',
          content:'📞 2026-06-06 10:12 통화 (1회차)\n[신규] 분양가·중도금 일정 첫 문의\n• 84B 관심, 자기자금 비율 질문\n• 중도금 무이자 횟수 확인\nAI 의견: 자금 계획표 발송 후 재상담 약속 권장.',
          agent_memo:'자금계획표 발송', memo:'신규' },
        { managed:1, date:'2026-06-05', call_count:3, customer:'박서아', phone:'010-0000-0023', region:'용인 기흥',
          content:'📞 2026-06-05 16:25 통화 (3회차)\n[계약임박] 자금 확보 완료 — 84A 계약 의사\n• 주택담보 사전심사 통과\n• 계약 가능일·동호수 우선권 문의\nAI 의견: 계약 직전 단계. 가용 동호수 즉시 안내 권장.',
          agent_memo:'동호수 우선 안내', memo:'가망 A' },
        { managed:0, date:'2026-06-05', call_count:1, customer:'정유나', phone:'010-0000-0024', region:'성남 분당',
          content:'📞 2026-06-05 13:05 통화 (1회차)\n[보류] 분양가 부담 — 청약 보류\n• 예산 대비 분양가 높다 판단\n• 추후 잔여세대 할인 시 재연락 희망\nAI 의견: 단기 전환 어려움. 분기 안부 연락 대상 관리.',
          agent_memo:'분기별 안부 연락', memo:'장기' },
        { managed:1, date:'2026-06-04', call_count:4, customer:'최건우', phone:'010-0000-0025', region:'평택 고덕',
          content:'📞 2026-06-04 17:18 통화 (4회차)\n[계약완료] 103동 1102호 정계약 체결\n• 계약금 납부 완료\n• 중도금 대출 은행·잔금 일정 안내 완료\nAI 의견: 계약 완료. 입주 지원 서비스 안내로 만족도 관리.',
          agent_memo:'계약 완료 ✅ 입주안내 발송', memo:'계약' },
        { managed:1, date:'2026-06-04', call_count:2, customer:'윤지아', phone:'010-0000-0026', region:'오산 세교',
          content:'📞 2026-06-04 11:50 통화 (2회차)\n[관심] 시스템에어컨·확장 옵션 비교\n• 옵션 일괄 계약 할인 여부\n• 84C 평면 수납 동선 질문\nAI 의견: 옵션 카탈로그+할인표 발송 후 재상담 권장.',
          agent_memo:'옵션 할인표 발송', memo:'가망 B' },
        { managed:1, date:'2026-06-03', call_count:1, customer:'임현우', phone:'010-0000-0027', region:'수원 권선구',
          content:'📞 2026-06-03 14:33 통화 (1회차)\n[신규] 청약 1순위 자격·일정 문의\n• 무주택 기간·청약통장 요건 질문\n• 특별공급 해당 여부 확인 요청\nAI 의견: 자격 안내 후 청약 일정 알림 등록 권장.',
          agent_memo:'청약 일정 알림 등록', memo:'신규' },
        { managed:1, date:'2026-06-03', call_count:2, customer:'한소율', phone:'010-0000-0028', region:'안산 단원구',
          content:'📞 2026-06-03 09:48 통화 (2회차)\n[관심] 84A vs 59B 평면·가격 비교\n• 1인 가구, 관리비·구조 효율 질문\n• 주차 대수·커뮤니티 시설 문의\nAI 의견: 비교표 제공 시 결정 빨라질 것. 방문 유도 권장.',
          agent_memo:'평면 비교표 발송', memo:'가망 B' },
        { managed:0, date:'2026-06-02', call_count:1, customer:'오지훈', phone:'010-0000-0029', region:'용인 처인구',
          content:'📞 2026-06-02 15:20 통화 (1회차)\n[부재] 부재중 — 안내 문자 발송\n• 통화 연결 안 됨\n• 분양 안내 + 모델하우스 위치 문자 전송\nAI 의견: 저녁 시간대 통화 재시도 권장.',
          agent_memo:'저녁에 재시도', memo:'부재' },
        { managed:1, date:'2026-06-02', call_count:3, customer:'강예린', phone:'010-0000-0030', region:'화성 봉담',
          content:'📞 2026-06-02 10:55 통화 (3회차)\n[계약임박] 부모 지원 자금 확정 — 계약 결심\n• 증여 절차 정리 완료\n• 84A 계약 의사 확정, 계약일 문의\nAI 의견: 자금 준비 완료. 계약일 즉시 확정 권장.',
          agent_memo:'계약일 조율 중', memo:'가망 A' },
        { managed:1, date:'2026-06-01', call_count:1, customer:'신민준', phone:'010-0000-0031', region:'수원 팔달구',
          content:'📞 2026-06-01 16:10 통화 (1회차)\n[신규] 발코니 확장 평면 변화 문의\n• 84C 확장 시 거실·방 크기 질문\n• 확장 비용·계약 시점 확인\nAI 의견: 확장 전후 평면도 발송 후 재상담 약속 권장.',
          agent_memo:'확장 평면도 발송', memo:'신규' },
        { managed:1, date:'2026-06-01', call_count:2, customer:'곽서현', phone:'010-0000-0032', region:'성남 수정구',
          content:'📞 2026-06-01 11:22 통화 (2회차)\n[관심] 저층·1층 프라이버시·테라스 문의\n• 어린 자녀로 저층 선호\n• 필로티·전용 테라스 여부 질문\nAI 의견: 저층 매물 맞춤 안내. 방문 상담 연결 권장.',
          agent_memo:'저층 동호수 안내', memo:'가망 B' },
        { managed:1, date:'2026-05-31', call_count:5, customer:'배준서', phone:'010-0000-0033', region:'평택 비전동',
          content:'📞 2026-05-31 17:35 통화 (5회차)\n[계약완료] 106동 904호 계약 체결\n• 계약금 입금 확인\n• 중도금 대출 안내·입주 지정일 협의 완료\nAI 의견: 계약 완료. 입주 예약 서비스 연계로 추가 만족도.',
          agent_memo:'계약 완료 ✅', memo:'계약' },
        { managed:1, date:'2026-05-31', call_count:1, customer:'문하린', phone:'010-0000-0034', region:'안성 공도',
          content:'📞 2026-05-31 13:40 통화 (1회차)\n[방문예약] 모델하우스 주말 방문 예약\n• 가족 동반 방문 희망\n• 주차·관람 소요시간 문의\nAI 의견: 방문 확정. 방문 전 리마인드 문자 권장.',
          agent_memo:'토요일 15시 방문 예약', memo:'방문예약' },
        { managed:0, date:'2026-05-30', call_count:1, customer:'노시우', phone:'010-0000-0035', region:'화성 향남',
          content:'📞 2026-05-30 15:02 통화 (1회차)\n[보류] 인근 신축과 비교 중 — 결정 보류\n• 입지·가격 비교 진행\n• 2주 뒤 재연락 희망\nAI 의견: 역세권·학군 강점 정리해 재접촉 권장.',
          agent_memo:'2주 뒤 비교자료로 재연락', memo:'경쟁' },
        { managed:1, date:'2026-05-30', call_count:2, customer:'천유주', phone:'010-0000-0036', region:'용인 수지구',
          content:'📞 2026-05-30 10:28 통화 (2회차)\n[관심] 중도금 무이자·이사지원 혜택 확인\n• 84A 관심, 혜택 패키지 질문\n• 사은품·잔금 일정 문의\nAI 의견: 혜택 명확화로 신뢰 형성. 계약 상담 일정 제안 권장.',
          agent_memo:'혜택 패키지 안내', memo:'가망 B' },
        { managed:1, date:'2026-05-29', call_count:3, customer:'황지율', phone:'010-0000-0037', region:'수원 영통구',
          content:'📞 2026-05-29 16:48 통화 (3회차)\n[계약임박] 부부 동반 방문 후 84B 결심\n• 부부 모두 만족, 자금 계획 확정\n• 계약 가능일 문의\nAI 의견: 결심 단계. 계약일 즉시 예약 권장.',
          agent_memo:'계약일 예약 진행', memo:'가망 A' },
        { managed:1, date:'2026-05-29', call_count:1, customer:'양도윤', phone:'010-0000-0038', region:'오산 세교',
          content:'📞 2026-05-29 11:15 통화 (1회차)\n[신규] 분양 일정·청약 자격 첫 문의\n• 1순위 요건·가점 질문\n• 모델하우스 운영 시간 확인\nAI 의견: 자격 안내 후 청약 독려. 일정 알림 등록 권장.',
          agent_memo:'청약 일정 알림 등록', memo:'신규' },
        { managed:1, date:'2026-05-28', call_count:2, customer:'구하영', phone:'010-0000-0039', region:'화성 동탄',
          content:'📞 2026-05-28 14:05 통화 (2회차)\n[관심] 직주근접 실거주 — 입주시기 조율\n• 직장 출퇴근 동선 고려\n• 입주 지정일·전입 시기 상담\nAI 의견: 실거주 니즈 뚜렷. 입주 일정 맞춤 안내로 견인.',
          agent_memo:'입주 일정표 전달', memo:'실거주' },
        { managed:1, date:'2026-05-28', call_count:4, customer:'서지후', phone:'010-0000-0040', region:'평택 고덕',
          content:'📞 2026-05-28 17:20 통화 (4회차)\n[당첨] 청약 당첨 — 계약서류 안내\n• 당첨 동호수 확인, 계약일 예약\n• 필요 서류·계약금 비율 안내\nAI 의견: 계약 확정적. 서류 체크리스트 문자 발송으로 이탈 방지.',
          agent_memo:'계약 6/02 14시 예약', memo:'당첨' },
    ];
    records = rows.map((d, i) => ({ id: 101 + i, groupId: 1, data: d }));
    renderRecords();
}

async function api(resource, opts = {}) {
    return apiRequest(resource, {
        method: opts.method || 'GET',
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        query: opts.query,
    });
}

/* ============== Groups ============== */
async function loadGroups() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=' + PAGE_TYPE });
        groups = data.items || [];
    } catch (e) {
        showError('그룹 로드 실패: ' + e.message);
        return;
    }
    if (groups.length === 0) {
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 등록된 그룹이 없습니다.</b><br>
                상단의 <b>+ 새 그룹</b> 버튼으로 첫 그룹을 만들어 주세요.
            </div>`;
        return;
    }
    if (expandedGroupIds.size === 0) {
        const def = groups.find(g => g.isDefault) || groups[0];
        expandedGroupIds.add(def.id);
    }
    await loadRecords();
}

function bindUI() {
    document.getElementById('newGroupBtn').addEventListener('click', () => openGroupModal(null));
    document.getElementById('groupCancelBtn').addEventListener('click', () => closeModal('groupModal'));
    document.getElementById('groupSaveBtn').addEventListener('click', saveGroup);
    document.getElementById('groupDeleteBtn').addEventListener('click', deleteGroup);

    document.getElementById('settingsCancelBtn').addEventListener('click', () => closeModal('settingsModal'));
    document.getElementById('settingsSaveBtn').addEventListener('click', saveSettings);
    document.getElementById('settingsDeleteBtn').addEventListener('click', deleteGroupFromSettings);

    document.getElementById('bulkClearBtn').addEventListener('click', () => { selectedIds.clear(); renderRecords(); });
    document.getElementById('bulkDeleteBtn').addEventListener('click', bulkDelete);
    document.getElementById('bulkSmsBtn')?.addEventListener('click', openSmsModal);

    document.getElementById('exportAllBtn')?.addEventListener('click', exportAllGroups);
}

/* ============== 설정 모달 (필드 관리) ============== */
let settingsGroupId = null;
let settingsFieldDraft = [];

function openSettingsModal(groupId) {
    settingsGroupId = groupId;
    const g = groups.find(x => x.id === groupId);
    if (!g) return;
    document.getElementById('settingsGroupName').textContent = g.name;
    document.getElementById('settingsErrorMsg').textContent = '';
    settingsFieldDraft = JSON.parse(JSON.stringify(g.settings?.customFields || []));
    mountFieldManager({
        container: document.getElementById('fieldManagerBox'),
        defaultFields: DEFAULT_FIELDS,
        customFields: settingsFieldDraft,
        onChange: (next) => { settingsFieldDraft = next; },
    });
    document.getElementById('settingsModal').classList.remove('hidden');
}

async function saveSettings() {
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    const newSettings = { ...(g.settings || {}), customFields: settingsFieldDraft };
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: g.id, settings: newSettings } });
        g.settings = newSettings;
        closeModal('settingsModal');
        renderRecords();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

async function deleteGroupFromSettings() {
    if (!settingsGroupId) return;
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    if (!confirm(`"${g.name}" 그룹과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?`)) return;
    try {
        await api('ledger-groups', { method: 'DELETE', body: { id: settingsGroupId } });
        closeModal('settingsModal');
        expandedGroupIds.delete(settingsGroupId);
        selectedExtraIds.delete(settingsGroupId);
        settingsGroupId = null;
        await loadGroups();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

/* ============== Group modal ============== */
function openGroupModal(groupId) {
    editingGroupId = groupId;
    const g = groupId ? groups.find(x => x.id === groupId) : null;
    document.getElementById('groupModalTitle').textContent = g ? '그룹 편집' : '새 그룹 만들기';
    document.getElementById('groupNameInput').value = g?.name || '';
    document.getElementById('groupIsDefaultInput').checked = !!g?.isDefault;
    document.getElementById('groupDeleteBtn').style.display = g ? '' : 'none';
    document.getElementById('groupErrorMsg').textContent = '';
    document.getElementById('groupModal').classList.remove('hidden');
}

async function saveGroup() {
    const name = document.getElementById('groupNameInput').value.trim();
    const isDefault = document.getElementById('groupIsDefaultInput').checked;
    if (!name) { document.getElementById('groupErrorMsg').textContent = '그룹 이름을 입력해주세요.'; return; }
    // 중복 이름 체크 (자기 자신 제외)
    const dup = groups.find(g => g.name === name && g.id !== editingGroupId);
    if (dup) { document.getElementById('groupErrorMsg').textContent = `이미 같은 이름의 그룹이 있습니다 ("${name}"). 다른 이름을 사용해 주세요.`; return; }
    try {
        if (editingGroupId) {
            await api('ledger-groups', { method: 'PATCH', body: { id: editingGroupId, name, isDefault } });
        } else {
            const created = await api('ledger-groups', {
                method: 'POST',
                body: { pageType: PAGE_TYPE, name, isDefault, fieldSchema: { fields: DEFAULT_FIELDS }, settings: {} },
            });
            expandedGroupIds.add(created.id);
            if (!isDefault) selectedExtraIds.add(created.id);
            extraPanelOpen = true;
        }
        closeModal('groupModal');
        await loadGroups();
    } catch (e) {
        document.getElementById('groupErrorMsg').textContent = e.message;
    }
}

async function deleteGroup() {
    if (!editingGroupId) return;
    if (!confirm('이 그룹과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?')) return;
    try {
        await api('ledger-groups', { method: 'DELETE', body: { id: editingGroupId } });
        closeModal('groupModal');
        expandedGroupIds.delete(editingGroupId);
        selectedExtraIds.delete(editingGroupId);
        await loadGroups();
    } catch (e) {
        document.getElementById('groupErrorMsg').textContent = e.message;
    }
}

/* ============== Records ============== */
let _sweptOnce = false;
async function loadRecords() {
    if (groups.length === 0) { records = []; renderRecords(); return; }
    try {
        const allIds = groups.map(g => g.id).join(',');
        const data = await api('ledger-records', { query: 'group_ids=' + allIds });
        records = data.items || [];
        // 페이지 첫 로드 시 1회 자동 sweep — 텍스트 없는 빈 행 일괄 정리.
        if (!_sweptOnce) {
            _sweptOnce = true;
            const blanks = findBlankRecordIds(records);
            if (blanks.length > 0) {
                try {
                    await api('ledger-records-bulk', { method: 'POST', body: { ids: blanks } });
                    const re = await api('ledger-records', { query: 'group_ids=' + allIds });
                    records = re.items || [];
                    showSweepToast(blanks.length);
                } catch (e) { console.warn('빈 행 자동 정리 실패:', e); }
            }
        }
    } catch (e) {
        showError('레코드 로드 실패: ' + e.message);
        records = [];
    }
    selectedIds.clear();
    renderRecords();
    // 사장님 2026-05-24 — 처리중 placeholder 있으면 polling 시작 (양식으로 전송 흐름).
    startProcessingPollIfNeeded();
}

/* 사장님 2026-05-24 — 양식으로 전송 흐름의 placeholder ledger 회차 자동 갱신.
 * content 안에 "(AI 요약 처리 중...)" 가 있는 record 가 1개라도 있으면 5초마다 silent refetch.
 * callback 완료 → ledger refresh 호출되면 다음 polling 에 새 content 도착.
 * page hidden 일 땐 polling skip. placeholder 사라지면 자동 중단. */
let _processingPollTimer = null;
function _hasProcessingPlaceholder() {
    return records.some(r => String(r.data?.content || '').includes('(AI 요약 처리 중...)'));
}
function startProcessingPollIfNeeded() {
    if (_processingPollTimer) return;
    if (!_hasProcessingPlaceholder()) return;
    _processingPollTimer = setInterval(async () => {
        if (document.hidden) return;
        try {
            if (groups.length === 0) return;
            const allIds = groups.map(g => g.id).join(',');
            const data = await api('ledger-records', { query: 'group_ids=' + allIds });
            records = data.items || [];
            renderRecords();
        } catch (e) { /* silent — 다음 tick 재시도 */ }
        if (!_hasProcessingPlaceholder()) {
            clearInterval(_processingPollTimer);
            _processingPollTimer = null;
        }
    }, 5000);
}

/* 검색 입력 → 기존 DOM 행을 hide/show. renderRecords() 호출 안 함 → input 요소 보존
   → 한글 IME 조합 안 깨짐 (모바일 keyboard 가 jamo 별 compositionend 발화하는 케이스 대응). */
function filterDOMRowsBySearch(gid, query) {
    const card = document.querySelector(`.accordion-card[data-gid="${gid}"]`);
    if (!card) return;
    // 사장님 2026-05-20 요청 — textContent + record.data 재귀 matching 통합.
    const normQ = normalizeSearchQuery(query);
    const rows = card.querySelectorAll('.ledger-cards > .ledger-card, .ledger-tbl tbody tr');
    rows.forEach(el => {
        if (el.querySelector('td[colspan]')) return;
        let matched = !normQ;
        if (!matched) {
            const txt = (el.textContent || '').normalize('NFC').toLowerCase();
            if (txt.includes(normQ)) matched = true;
        }
        if (!matched) {
            const rid = el.dataset.id || el.querySelector('[data-id]')?.dataset.id;
            const rec = rid ? records.find(r => String(r.id) === String(rid)) : null;
            if (rec && deepSearchMatch(rec.data, normQ)) matched = true;
        }
        el.style.display = matched ? '' : 'none';
    });
}

function applyFilters(rows, groupId) {
    let out = applyColumnFilters(filterState.filters, rows, (r, k) => r.data?.[k]);
    // 사장님 2026-05-20 요청 — Object.values 의 [object Object] 문제 해결. file/ref inner value 까지 매칭.
    const normQ = normalizeSearchQuery(searchByGroup[groupId] || '');
    if (normQ !== '') {
        out = out.filter(r => deepSearchMatch(r.data || {}, normQ));
    }
    // 모바일 '관리중만 보기' — 비관리중 행 숨김. applyFilters 가 렌더와 전체선택 양쪽에 쓰여
    // 숨긴 행은 전체선택에서도 자동 제외된다. (PC 는 컬럼 필터로 처리하므로 모바일에서만)
    if (isLedgerMobile() && managedOnlyByGroup[groupId]) {
        out = out.filter(r => !!r.data?.managed);
    }
    return out;
}

function renderRecords() {
    const content = document.getElementById('content');
    if (groups.length === 0) return;

    const mainGroup = groups.find(g => g.isDefault) || groups[0];
    const others = groups.filter(g => g.id !== mainGroup.id);

    // bulk-bar 가 #content 자식이면 innerHTML 갱신 시 같이 삭제되므로 잠시 body 로 옮김.
    const bulkBar = document.getElementById('bulkBar');
    if (bulkBar && bulkBar.parentElement === content) {
        document.body.appendChild(bulkBar);
    }

    let html = '';
    html += renderGroupPicker(groups, mainGroup.id);
    html += renderGroupCard(mainGroup);
    others.filter(g => selectedExtraIds.has(g.id)).forEach(g => {
        html += renderGroupCard(g);
    });
    content.innerHTML = html;

    // 사용자 요청 배치: [그룹목록(extra-groups)] → [bulk-bar] → [오산롯데 등 그룹 카드]
    const picker = content.querySelector('.extra-groups');
    if (bulkBar && picker) {
        picker.insertAdjacentElement('afterend', bulkBar);
    }

    bindAccordionEvents();
    bindExtraPickerEvents();
    bindTableEvents();
    updateBulkBar();
}

function renderGroupPicker(allGroups, mainId) {
    const others = allGroups.filter(g => g.id !== mainId);
    const showing = others.filter(g => selectedExtraIds.has(g.id)).length + 1; // 메인은 항상 표시
    return `
        <div class="extra-groups ${extraPanelOpen ? 'open' : ''}">
            <div class="extra-head">
                <button class="extra-toggle" data-toggle-extra type="button">
                    <span class="extra-arrow">▶</span>
                    <h4>그룹목록 <span class="extra-count">${allGroups.length}개</span><span class="extra-count-sub">· ${showing}개 표시 중</span></h4>
                </button>
            </div>
            <div class="extra-picker">
                ${allGroups.map(g => {
                    const isMain = g.id === mainId;
                    const active = isMain || selectedExtraIds.has(g.id);
                    return `<button type="button"
                            class="group-chip ${active ? 'active' : ''} ${isMain ? 'main' : ''}"
                            ${isMain ? 'disabled title="메인그룹 — 항상 표시됩니다"' : `data-toggle-extra-id="${g.id}"`}>
                        ${isMain ? '★ ' : ''}${escapeHtml(g.name)}
                    </button>`;
                }).join('')}
            </div>
        </div>`;
}

function bindExtraPickerEvents() {
    document.querySelectorAll('[data-toggle-extra]').forEach(b => {
        b.addEventListener('click', () => { extraPanelOpen = !extraPanelOpen; renderRecords(); });
    });
    document.querySelectorAll('[data-toggle-extra-id]').forEach(b => {
        b.addEventListener('click', () => {
            const id = parseInt(b.dataset.toggleExtraId, 10);
            if (selectedExtraIds.has(id)) selectedExtraIds.delete(id);
            else selectedExtraIds.add(id);
            renderRecords();
        });
    });
}

function renderGroupCard(group) {
    const grpRecs = records.filter(r => r.groupId === group.id);
    const open = expandedGroupIds.has(group.id);
    const bodyHtml = open ? renderTable(group, applyFilters(grpRecs, group.id)) : '';
    return `
        <div class="accordion-card ${open ? 'open' : ''}" data-gid="${group.id}">
            <div class="accordion-head">
                <h3 class="accordion-title" data-edit-title-gid="${group.id}" title="그룹 이름 수정">${escapeHtml(group.name)} <span class="head-count">(${grpRecs.length}건)</span><span class="title-edit-ico" aria-hidden="true">✎</span></h3>
                <label class="main-checkbox" title="이 그룹을 메인으로 설정">
                    <input type="checkbox" data-set-main="${group.id}" ${group.isDefault ? 'checked' : ''}>
                    <span>메인그룹</span>
                </label>
                <div class="head-actions">
                    <button type="button" data-toggle-gid="${group.id}" title="${open ? '접기' : '펼치기'}">${open ? '▼ 접기' : '▶ 펼치기'}</button>
                    <button type="button" data-export-gid="${group.id}" title="이 그룹을 엑셀로 다운로드">📥 엑셀 다운로드</button>
                    <button type="button" data-import-gid="${group.id}" title="엑셀 파일을 이 그룹에 업로드">📤 엑셀 가져오기</button>
                    ${loadImportSession(PAGE_TYPE, group.id) ? `<button type="button" data-reimport-gid="${group.id}" title="마지막 가져오기 매핑 다시 열어 수정">🔄 매핑 수정</button>` : ''}
                    <button type="button" data-edit-gid="${group.id}">편집</button>
                    <button type="button" data-settings-gid="${group.id}">⚙ 설정</button>
                    ${isLedgerMobile() ? (() => { const mo = !!managedOnlyByGroup[group.id]; return `<button type="button" data-managed-only-gid="${group.id}" title="관리중 항목만 보기" style="margin-left:auto;border:1px solid ${mo ? 'var(--ledger-accent)' : 'var(--ledger-line-strong)'}!important;background:${mo ? '#fff1f0' : '#fff'}!important;color:${mo ? 'var(--ledger-accent)' : '#5a534c'}!important;font-weight:600!important;border-radius:7px!important;">${mo ? '☑' : '☐'} 관리중만 보기</button>`; })() : ''}
                </div>
            </div>
            <div class="accordion-body">${bodyHtml}</div>
        </div>`;
}

function renderTable(group, rows) {
    if (isLedgerMobile()) return renderMobileCards(group, rows);
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const q = escapeAttr(searchByGroup[group.id] || '');
    return `
        <div class="ledger-card-toolbar">
            <div class="ledger-search-wrap">
                <input type="search" class="ledger-search-input" data-search-gid="${group.id}" value="${q}" placeholder="🔍 행 안에서 검색…" autocomplete="off">
                ${q ? `<button type="button" class="ledger-search-clear" data-search-clear-gid="${group.id}" aria-label="검색 지우기">×</button>` : ''}
            </div>
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 새 고객 추가</button>
        </div>
        <div class="tbl-wrap">
            <table class="ledger-tbl">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" data-select-all="${group.id}"></th>
                        ${fields.map(f => `<th style="min-width:${f.width || 110}px;" data-col-key="${f.key}">${escapeHtml(f.label)}</th>`).join('')}
                        <th class="col-action"></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.length === 0
                        ? `<tr><td colspan="${fields.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">표시할 항목이 없습니다.</td></tr>`
                        : rows.map((r, i) => renderRow(r, i + 1, group)).join('')}
                </tbody>
            </table>
        </div>`;
}

function renderMobileCards(group, rows) {
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const cardsHtml = rows.length === 0
        ? `<div class="ledger-cards-empty">표시할 항목이 없습니다.</div>`
        : rows.map((r, i) => renderMobileCard(r, i + 1, group, fields)).join('');
    const q = escapeAttr(searchByGroup[group.id] || '');
    const allSelected = rows.length > 0 && rows.every(r => selectedIds.has(r.id));
    const selectAllBar = rows.length === 0 ? '' : `
        <label class="ledger-cards-selectall">
            <input type="checkbox" data-select-all="${group.id}" ${allSelected ? 'checked' : ''}>
            <span>전체선택</span>
            <span class="ledger-cards-selectall-count">(${rows.length}건)</span>
        </label>`;
    return `
        <div class="ledger-cards-toolbar">
            <div class="ledger-search-wrap">
                <input type="search" class="ledger-search-input" data-search-gid="${group.id}" value="${q}" placeholder="🔍 행 안에서 검색…" autocomplete="off">
                ${q ? `<button type="button" class="ledger-search-clear" data-search-clear-gid="${group.id}" aria-label="검색 지우기">×</button>` : ''}
            </div>
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 새 고객 추가</button>
        </div>
        ${selectAllBar}
        <div class="ledger-cards">${cardsHtml}</div>`;
}

function renderMobileCard(r, displayNo, group, fields) {
    const d = r.data || {};
    const dead = !d.managed;
    const rowCls = ['ledger-card', selectedIds.has(r.id) ? 'selected' : '', dead ? 'row-dead' : ''].filter(Boolean).join(' ');
    // 핵심 정보 (접힘 시 표시)
    const primaryField = fields.find(f => f.key === 'customer') || fields.find(f => f.type !== 'auto_number');
    const titleVal = primaryField ? (d[primaryField.key] || '-') : '-';
    // 통화수 — 1회 이상이면 이름 옆에 "(N)번 통화함" 자연어 형식으로 노출.
    const callCountNum = parseInt(d.call_count, 10);
    const callCountTag = (Number.isFinite(callCountNum) && callCountNum >= 1)
        ? ` <span class="ledger-card-call-tag">(${callCountNum})번 통화함</span>`
        : '';
    const subFieldKeys = MOBILE_PRIMARY_KEYS.filter(k => k !== (primaryField?.key));
    const subParts = subFieldKeys.map(k => {
        const f = fields.find(x => x.key === k);
        if (!f) return '';
        const v = d[k];
        let display;
        if (!v) display = '-';
        else if (f.type === 'date') {
            const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
            display = m ? `${m[1].slice(2)}.${m[2]}.${m[3]}` : String(v).replace(/-/g, '.');
        }
        else display = String(v);
        return `
            <div class="ledger-card-sub-item">
                <span class="ledger-card-sub-label">${escapeHtml(f.label || '')}</span>
                <span class="ledger-card-sub-val">${escapeHtml(display)}</span>
            </div>`;
    }).filter(Boolean).join('');
    // 상세 (펼침 시 표시) — primary 외 모든 필드
    const detailFields = fields.filter(f => f.type !== 'auto_number' && f.key !== primaryField?.key);
    const detailHtml = detailFields.map(f => `
        <div class="ledger-card-field">
            <span class="ledger-card-label">${escapeHtml(f.label || '')}</span>
            <span class="ledger-card-value">${renderCell(f, r, d, displayNo)}</span>
        </div>
    `).join('');
    return `
        <div class="${rowCls}" data-id="${r.id}" data-gid="${group.id}">
            <div class="ledger-card-head">
                <input type="checkbox" class="ledger-card-check" data-select="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}>
                <div class="ledger-card-summary">
                    <div class="ledger-card-title">${escapeHtml(titleVal)}${callCountTag}</div>
                    ${subParts ? `<div class="ledger-card-sub">${subParts}</div>` : ''}
                </div>
                <button class="ledger-card-toggle" type="button" aria-label="펼치기/접기"><span class="toggle-label-open">펼치기</span><span class="toggle-label-close">접기</span></button>
            </div>
            <div class="ledger-card-body">
                ${detailHtml}
                <div class="ledger-card-actions">
                    <button class="row-action-btn" data-edit-row="${r.id}" type="button"><span class="ico">✎</span><span class="lbl">수정</span></button>
                    <button class="row-action-btn danger" data-delete-row="${r.id}" type="button"><span class="ico">×</span><span class="lbl">삭제</span></button>
                </div>
            </div>
        </div>`;
}

function bindAccordionEvents() {
    document.querySelectorAll('[data-edit-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openGroupModal(parseInt(b.dataset.editGid, 10)); });
    });
    // 그룹 제목 직접 탭 → 이름 수정 (특히 모바일에서 편집 버튼이 묻혀 안 보이던 문제 해소)
    document.querySelectorAll('[data-edit-title-gid]').forEach(el => {
        el.addEventListener('click', (e) => { e.stopPropagation(); openGroupModal(parseInt(el.dataset.editTitleGid, 10)); });
    });
    document.querySelectorAll('[data-settings-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openSettingsModal(parseInt(b.dataset.settingsGid, 10)); });
    });
    document.querySelectorAll('[data-managed-only-gid]').forEach(b => {
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            const gid = parseInt(b.dataset.managedOnlyGid, 10);
            managedOnlyByGroup[gid] = !managedOnlyByGroup[gid];
            // ON 시 숨겨질 비관리중 행은 선택 해제 (안 보이는데 선택된 채 일괄작업 되는 것 방지)
            if (managedOnlyByGroup[gid]) {
                records.filter(r => r.groupId === gid && !r.data?.managed).forEach(r => selectedIds.delete(r.id));
            }
            renderRecords();
        });
    });
    document.querySelectorAll('[data-export-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); exportGroup(parseInt(b.dataset.exportGid, 10)); });
    });
    document.querySelectorAll('[data-import-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); importToGroup(parseInt(b.dataset.importGid, 10)); });
    });
    document.querySelectorAll('[data-reimport-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); reopenImportSession(parseInt(b.dataset.reimportGid, 10)); });
    });
    document.querySelectorAll('[data-toggle-gid]').forEach(b => {
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            const gid = parseInt(b.dataset.toggleGid, 10);
            if (expandedGroupIds.has(gid)) expandedGroupIds.delete(gid);
            else expandedGroupIds.add(gid);
            renderRecords();
        });
    });
    document.querySelectorAll('[data-set-main]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.dataset.setMain, 10);
            if (cb.checked) setMainGroup(id);
            else cb.checked = true;  // 메인은 항상 1개 — 단독으로 해제 불가
        });
    });
}

async function setMainGroup(gid) {
    const target = groups.find(g => g.id === gid);
    if (!target || target.isDefault) return;
    const currentMain = groups.find(g => g.isDefault);
    try {
        // 백엔드가 같은 owner+page_type 내 다른 default 를 자동 해제 — PATCH 한 번으로 OK.
        await api('ledger-groups', { method: 'PATCH', body: { id: gid, isDefault: true } });
        if (currentMain) {
            selectedExtraIds.add(currentMain.id);   // 옛 메인은 picker 에 선택 상태로 유지
            extraPanelOpen = true;
        }
        selectedExtraIds.delete(gid);
        await loadGroups();
    } catch (e) {
        alert('메인 그룹 변경 실패: ' + (e.message || ''));
    }
}

function renderRow(r, displayNo, group) {
    const d = r.data || {};
    const dead = !d.managed;
    const cls = [
        selectedIds.has(r.id) ? 'selected' : '',
        dead ? 'row-dead' : '',
    ].filter(Boolean).join(' ');
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const primaryIdx = fields.findIndex(f => f.type !== 'auto_number');
    return `
        <tr data-id="${r.id}" data-gid="${group.id}" class="${cls}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}></td>
            ${fields.map((f, i) => {
                const cls = [
                    f.type === 'auto_number' ? 'col-no' : '',
                    i === primaryIdx ? 'col-primary' : '',
                ].filter(Boolean).join(' ');
                const label = f.type === 'auto_number' ? '' : (f.label || '');
                return `<td${cls ? ` class="${cls}"` : ''}${label ? ` data-label="${escapeHtml(label)}"` : ''}>${renderCell(f, r, d, displayNo)}</td>`;
            }).join('')}
            <td class="col-action">
                <button class="row-action-btn" data-edit-row="${r.id}" title="수정"><span class="ico">✎</span><span class="lbl">수정</span></button>
                <button class="row-action-btn danger" data-delete-row="${r.id}" title="삭제"><span class="ico">×</span><span class="lbl">삭제</span></button>
            </td>
        </tr>`;
}

function renderCell(f, r, d, displayNo) {
    const id = r.id;
    const v = d[f.key];
    if (f.type === 'auto_number') return `<span class="col-no">${displayNo}</span>`;
    if (f.type === 'manage_switch') {
        const on = !!d.managed;
        return `
            <label class="toggle-switch ${on ? 'on' : 'off'}" data-manage-switch data-id="${id}" title="${on ? '관리중' : '비관리중'}">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label">${on ? '관리중' : '비관리중'}</span>
            </label>`;
    }
    if (f.type === 'call_count') {
        // 통화수 — 같은 phone 으로 들어온 횟수 자동 계산 (records.php 가 저장 시 채움).
        const num = parseInt(v, 10);
        if (Number.isFinite(num) && num >= 1) {
            return `<span class="cell-text" style="font-variant-numeric:tabular-nums;font-weight:600;">${num}회</span>`;
        }
        return `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'textarea' || f.type === 'text') {
        if (!v) return `<span class="cell-empty">-</span>`;
        // 모든 텍스트 입력 셀 — 행 크기 고정 (2줄 clamp + 가운데 정렬) + 클릭 시 상세 모달.
        // 셀 미리보기는 previewContent — 날짜/회차 header 라인 제거하고 summary 만 (2줄 보장).
        return `<span class="cell-text cell-multiline cell-multiline-clamp" data-cell-detail data-id="${id}" title="클릭하여 상세 보기">${escapeHtml(previewContent(v))}</span>`;
    }
    if (f.type === 'tel') {
        if (!v) return `<span class="cell-empty">-</span>`;
        // tel 은 1줄 nowrap + ellipsis 로 wrap 차단 (010-xxxx-xxxx 가 - 에서 줄바꿈되는 문제 회피).
        return `<span class="cell-text cell-tel-nowrap" data-cell-detail data-id="${id}" title="클릭하여 상세 보기">${escapeHtml(v)}</span>`;
    }
    if (f.type === 'date') {
        return v ? `<span class="cell-text">${formatDateDisplay(v)}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'toggle') {
        const on = !!v;
        return `<span class="toggle-cell ${on ? 'on' : 'off'}" data-cell-toggle data-id="${id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" data-on-label="${escapeAttr(f.onLabel || 'ON')}" data-off-label="${escapeAttr(f.offLabel || 'OFF')}" title="클릭하여 토글">${escapeHtml(on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF'))}</span>`;
    }
    if (f.type === 'switch') {
        const on = !!v;
        const lbl = on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF');
        return `<span class="switch-cell ${on ? 'on' : 'off'}" data-cell-switch data-id="${id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" aria-label="${escapeAttr(lbl)}" title="클릭하여 토글"><span class="switch-track"><span class="switch-thumb"></span></span><span class="switch-label">${escapeHtml(lbl)}</span></span>`;
    }
    // tel / text / number / resident_id / 기타: 모두 read-only span (편집은 ✎ 수정 버튼 → 모달)
    return v ? `<span class="cell-text">${escapeHtml(v)}</span>` : `<span class="cell-empty">-</span>`;
}

function formatDateDisplay(v) {
    if (!v) return '';
    // YYYY-MM-DD → YY.MM.DD (6자리, 컬럼 가로폭 절약)
    const s = String(v);
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return escapeHtml(`${m[1].slice(2)}.${m[2]}.${m[3]}`);
    return escapeHtml(s.replace(/-/g, '.'));
}

function bindTableEvents() {
    attachColumnFilters({
        state: filterState,
        headers: document.querySelectorAll('.ledger-tbl thead th[data-col-key]'),
        fields: DEFAULT_FIELDS,
        getRows: () => records,
        getValue: (r, k) => r.data?.[k],
        onChange: () => renderRecords(),
        labelFor: (field, raw) => {
            if (field.key === 'managed') {
                if (raw === 'true' || raw === true) return '관리중';
                if (raw === 'false' || raw === false) return '비관리중';
                return raw;
            }
            return undefined;
        },
    });
    document.querySelectorAll('[data-add-row]').forEach(b => {
        b.addEventListener('click', () => addRow(parseInt(b.dataset.gid, 10)));
    });
    document.querySelectorAll('[data-edit-row]').forEach(b => {
        b.addEventListener('click', () => editRow(parseInt(b.dataset.editRow, 10)));
    });
    document.querySelectorAll('[data-manage-switch]').forEach(b => {
        b.addEventListener('click', (e) => { e.preventDefault(); toggleManaged(parseInt(b.dataset.id, 10)); });
    });
    // textarea 셀 (내용 / 담당자 메모 등) — 2줄 truncate 표시 + 클릭 시 상세 모달.
    document.querySelectorAll('[data-cell-detail]').forEach(el => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(el.dataset.id, 10);
            const r = records.find(x => x.id === id);
            if (!r) return;
            const group = groups.find(g => g.id === r.groupId);
            const fields = getEffectiveFields(group, DEFAULT_FIELDS);
            openRowDetailModal(r, group, fields);
        });
    });
    // 그룹별 검색 입력 — DOM 재생성 없이 기존 행만 hide/show. IME(한글) 조합 보존 핵심.
    document.querySelectorAll('[data-search-gid]').forEach(input => {
        const gid = parseInt(input.dataset.searchGid, 10);
        let timer = null;
        const trigger = () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                searchByGroup[gid] = input.value;
                filterDOMRowsBySearch(gid, input.value);
            }, 80);
        };
        // input 이벤트만 — composition 도중에도 안전 (DOM 재생성 안 함).
        input.addEventListener('input', trigger);
    });
    document.querySelectorAll('[data-search-clear-gid]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const gid = parseInt(btn.dataset.searchClearGid, 10);
            delete searchByGroup[gid];
            renderRecords();
        });
    });

    // 사용자 정의 toggle/switch + 레벨 pill 셀 클릭 즉시 토글/순환 (desktop 표 + mobile 카드 둘 다 커버)
    attachCellClickHandlers({
        root: document,
        onToggle: async ({ id, fieldKey, nextValue }) => {
            await updateRowField(id, fieldKey, nextValue);
            renderRecords();
        },
        onCycle: async ({ id, fieldKey, nextValue }) => {
            await updateRowField(id, fieldKey, nextValue);
            renderRecords();
        },
    });
    document.querySelectorAll('[data-delete-row]').forEach(b => {
        b.addEventListener('click', () => deleteRow(parseInt(b.dataset.deleteRow, 10)));
    });
    document.querySelectorAll('[data-select]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.dataset.select, 10);
            if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
            cb.closest('tr')?.classList.toggle('selected', cb.checked);
            updateBulkBar();
        });
    });
    document.querySelectorAll('[data-select-all]').forEach(cb => {
        cb.addEventListener('change', () => {
            const gid = parseInt(cb.dataset.selectAll, 10);
            const targets = applyFilters(records.filter(r => r.groupId === gid), gid);
            targets.forEach(r => cb.checked ? selectedIds.add(r.id) : selectedIds.delete(r.id));
            renderRecords();
        });
    });
}

function addRow(gid) {
    const today = new Date().toISOString().slice(0, 10);
    const group = groups.find(g => g.id === gid);
    openRowAddModal({
        title: '새 고객 추가',
        fields: getEffectiveFields(group, DEFAULT_FIELDS),
        defaults: { date: today, managed: true },
        customRender: customerCustomRender,
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'web' } });
            await loadRecords();
        },
    });
}

function editRow(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const group = groups.find(g => g.id === r.groupId);
    openRowAddModal({
        title: '고객 정보 수정',
        confirmLabel: '저장',
        fields: getEffectiveFields(group, DEFAULT_FIELDS),
        defaults: { ...r.data },
        customRender: customerCustomRender,
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'PATCH', body: { id, data } });
            await loadRecords();
        },
    });
}

/**
 * 행 상세 보기 모달 — read-only. 긴 텍스트 셀 클릭 시 호출.
 * 모든 필드를 라벨/값 쌍으로 정렬해 표시. 텍스트 영역은 전체 펼침.
 * 푸터의 "수정" 버튼은 기존 editRow 모달로 전환.
 */
function openRowDetailModal(rec, group, fields) {
    closeRowDetailModal();
    const d = rec.data || {};
    // 모달은 고객 정체성 우선 — '고객명' 을 '관리' 바로 다음으로 reorder.
    // 그 외 필드는 DEFAULT_FIELDS 순서 그대로.
    const allVisible = (fields || []).filter(f => f.type !== 'auto_number');
    const customerField = allVisible.find(f => f.key === 'customer');
    const visible = [];
    let customerInserted = false;
    allVisible.forEach(f => {
        if (f.key === 'customer') return;
        visible.push(f);
        if (f.key === 'managed' && customerField) {
            visible.push(customerField);
            customerInserted = true;
        }
    });
    if (customerField && !customerInserted) visible.unshift(customerField);
    const rowsHtml = visible.map(f => {
        const v = d[f.key];
        let display = '';
        if (v === undefined || v === null || v === '') {
            display = '<span class="row-detail-empty">-</span>';
        } else if (f.type === 'manage_switch') {
            display = v
                ? '<span class="row-detail-pill on">관리중</span>'
                : '<span class="row-detail-pill off">비관리중</span>';
        } else if (f.type === 'call_count') {
            const n = parseInt(v, 10);
            display = Number.isFinite(n) && n >= 1
                ? `<span class="row-detail-num">${n}회</span>`
                : '<span class="row-detail-empty">-</span>';
        } else if (f.type === 'date') {
            display = escapeHtml(String(v).replace(/-/g, '.'));
        } else if (f.type === 'textarea') {
            // 사장님 2026-05-20 요청 — content 필드의 회차별 요약 마지막에 "대화내용 전문보기" 버튼 inject.
            if (f.key === 'content') {
                display = renderContentWithTranscriptButtons(sanitizeContent(v), d);
            } else {
                display = `<div class="row-detail-textarea">${escapeHtml(sanitizeContent(v))}</div>`;
            }
        } else {
            display = escapeHtml(String(v));
        }
        return `<div class="row-detail-row">
            <span class="row-detail-label">${escapeHtml(f.label || '')}</span>
            <div class="row-detail-value">${display}</div>
        </div>`;
    }).join('');

    const md = document.createElement('div');
    md.className = 'modal-backdrop row-detail-modal';
    md.style.zIndex = '350';
    md.innerHTML = `
        <div class="modal-panel">
            <header class="modal-header">
                <div>
                    <h2>상세 정보</h2>
                    <p class="modal-subtitle">${escapeHtml(group?.name || '')}</p>
                </div>
            </header>
            <div class="modal-body">${rowsHtml}</div>
            <footer class="modal-footer">
                <button class="tiny-btn" type="button" data-close>닫기</button>
                <button class="tiny-btn primary" type="button" data-edit>수정</button>
            </footer>
        </div>`;
    document.body.appendChild(md);
    const close = () => closeRowDetailModal();
    md.querySelector('[data-close]').addEventListener('click', close);
    md.querySelector('[data-edit]').addEventListener('click', () => { close(); editRow(rec.id); });
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
    // 대화내용 전문보기 버튼 bind (사장님 2026-05-20).
    bindTranscriptButtons(md, d.phone || d.phone_number || '');
}

/* 회차별 content 분할 + "전문보기" 버튼 inject (사장님 2026-05-20 요청).
 * 패턴: "📞 YYYY-MM-DD HH:MM:SS 통화 (N회차)" 헤더 마다 새 block 시작.
 * 각 block 끝에 data-transcript-ts 버튼 노출. 클릭 시 phone 의 transcript fetch + 큰 모달 표시.
 * 옛 1회차 데이터(헤더 없음) 도 record.data 의 date/call_count 로 fake header fallback 적용. */
/* 회차 본문 HTML — 보고서형(불릿 포함)이면 제목 줄(헤더 다음 첫 본문 줄)을 굵게.
 * 줄글형은 그대로. 사장님 2026-06-12 — 보고서형 제목 강조. */
function formatRoundBodyHtml(block) {
    const lines = String(block ?? '').split('\n');
    const isReport = lines.some(l => l.trimStart().startsWith('• '));
    let titleDone = false;
    return lines.map((ln, idx) => {
        const esc = escapeHtml(ln);
        if (idx === 0 && /^📞/.test(ln.trim())) return esc; // 회차 헤더
        if (isReport && !titleDone && ln.trim() !== ''
            && !ln.trimStart().startsWith('•') && !/^AI 의견\s*:/.test(ln.trim())) {
            titleDone = true;
            return `<strong class="report-title">${esc}</strong>`;
        }
        return esc;
    }).join('\n');
}

function renderContentWithTranscriptButtons(text, rowData) {
    const src = String(text ?? '');
    if (!src.trim()) return `<div class="row-detail-textarea"></div>`;
    const re = /📞\s*(\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}:\d{2})?)\s*통화\s*\((\d+)회차\)/g;
    const headers = [];
    let m;
    while ((m = re.exec(src)) !== null) {
        headers.push({ index: m.index, end: m.index + m[0].length, ts: m[1], round: m[2] });
    }
    // 사장님 2026-05-24 — 회차 ↔ customer_log_id 자물쇠 매핑 (server data_json.round_log_ids).
    // 회차 카드 ↔ transcript 영구 결합 → timestamp 매칭 실패해도 다른 회차 transcript 와 혼선 차단.
    const roundLogIds = (rowData && rowData.round_log_ids && typeof rowData.round_log_ids === 'object')
        ? rowData.round_log_ids : {};
    const cidFor = (round) => {
        const v = roundLogIds[String(round)] || roundLogIds[round];
        return typeof v === 'string' && v ? v : '';
    };
    if (headers.length === 0) {
        // 옛 1회차 데이터 — 헤더 없이 본문만. fake header 생성해서 전문보기 버튼 노출.
        const fallbackDate = (rowData && (rowData.date || rowData.consult_at)) || '';
        const round = (rowData && rowData.call_count) ? String(rowData.call_count) : '1';
        const tsForMatch = fallbackDate;
        const cidAttr = cidFor(round) ? ` data-customer-log-id="${escapeAttr(cidFor(round))}"` : '';
        return `<div class="row-detail-textarea content-rounds">
            <div class="content-round-block" data-round="${escapeAttr(round)}">
                <div class="content-round-body">${fallbackDate ? `📞 ${escapeHtml(fallbackDate)} 통화 (${escapeHtml(round)}회차)\n\n` : ''}${escapeHtml(src)}</div>
                <div class="content-round-foot">
                    <button type="button" class="content-transcript-btn" data-transcript-ts="${escapeAttr(tsForMatch)}" data-transcript-round="${escapeAttr(round)}"${cidAttr} title="대화내용 전문보기">
                        <span class="ico">📄</span><span>전문보기</span>
                    </button>
                </div>
            </div>
        </div>`;
    }
    let html = '';
    for (let i = 0; i < headers.length; i++) {
        const h = headers[i];
        const blockEnd = i + 1 < headers.length ? headers[i + 1].index : src.length;
        const block = src.slice(h.index, blockEnd).replace(/\s+$/, '');
        // 사장님 2026-05-24 — placeholder 회차 (AI 요약 처리 중) 시각화.
        // trigger_summarize(auto_confirm=1) 직후 → callback 완료 전 상태.
        // "전문보기" 버튼 숨김 (transcript 아직 NULL), 회차 본문은 회색 + spinner.
        const isProcessing = block.includes('(AI 요약 처리 중...)');
        if (isProcessing) {
            html += `
                <div class="content-round-block content-round-processing" data-round="${escapeAttr(h.round)}">
                    <div class="content-round-body">${escapeHtml(block)}</div>
                </div>`;
        } else {
            const cidAttr = cidFor(h.round) ? ` data-customer-log-id="${escapeAttr(cidFor(h.round))}"` : '';
            html += `
                <div class="content-round-block" data-round="${escapeAttr(h.round)}">
                    <div class="content-round-body">${formatRoundBodyHtml(block)}</div>
                    <div class="content-round-foot">
                        <button type="button" class="content-transcript-btn" data-transcript-ts="${escapeAttr(h.ts)}" data-transcript-round="${escapeAttr(h.round)}"${cidAttr} title="대화내용 전문보기">
                            <span class="ico">📄</span><span>전문보기</span>
                        </button>
                    </div>
                </div>`;
        }
    }
    return `<div class="row-detail-textarea content-rounds">${html}</div>`;
}

/* phone 별 customer_log transcript 캐시 (모달 1회 fetch). */
const _transcriptCacheByPhone = new Map();
/* id 별 단건 캐시 — 자물쇠 모드 (사장님 2026-05-24). */
const _transcriptCacheById = new Map();

async function fetchTranscriptsByPhone(phone) {
    if (!phone) return [];
    if (_transcriptCacheByPhone.has(phone)) return _transcriptCacheByPhone.get(phone);
    try {
        const r = await api('customer-log', { query: 'action=transcripts_by_phone&phone=' + encodeURIComponent(phone) });
        const items = Array.isArray(r?.items) ? r.items : [];
        _transcriptCacheByPhone.set(phone, items);
        return items;
    } catch (e) {
        console.warn('[transcripts] fetch 실패', e);
        return [];
    }
}

/* 사장님 2026-05-24 — id 직접 조회 (회차 자물쇠).
 * data-customer-log-id 가 있으면 phone 매칭 없이 customer_log 단건 직접 조회 → 혼선 0%. */
async function fetchTranscriptById(cid) {
    if (!cid) return null;
    if (_transcriptCacheById.has(cid)) return _transcriptCacheById.get(cid);
    try {
        const r = await api('customer-log', { query: 'action=get_transcript_by_id&id=' + encodeURIComponent(cid) });
        const item = r && r.item && typeof r.item === 'object' ? r.item : null;
        _transcriptCacheById.set(cid, item);
        return item;
    } catch (e) {
        console.warn('[transcript by id] fetch 실패', e);
        return null;
    }
}

/* timestamp 와 가장 가까운 transcript row 찾기 (legacy fallback — round_log_ids 없는 옛 ledger row 용).
 * 사장님 2026-05-24 강화 — 1분 cap + 같은 날짜 find() 첫 row 반환 패턴 제거.
 * 항상 시간 차 최소인 best row 반환. items 비어있으면 null.
 * (회차 자물쇠 모드면 이 함수 안 탐. 옛 데이터에만 적용.) */
function _findTranscriptByTimestamp(items, ts) {
    if (!items.length) return null;
    const tsStr = String(ts || '');
    const targetMs = new Date(tsStr.replace(' ', 'T')).getTime();
    if (Number.isFinite(targetMs)) {
        let best = null, bestDiff = Infinity;
        for (const it of items) {
            const itMs = new Date(String(it.consult_at).replace(' ', 'T')).getTime();
            if (!Number.isFinite(itMs)) continue;
            const diff = Math.abs(itMs - targetMs);
            if (diff < bestDiff) { bestDiff = diff; best = it; }
        }
        if (best) return best;
    }
    if (items.length === 1) return items[0];
    return null;
}

function bindTranscriptButtons(rootEl, phone) {
    if (!rootEl) return;
    rootEl.querySelectorAll('[data-transcript-ts]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const ts = btn.dataset.transcriptTs;
            const round = btn.dataset.transcriptRound || '';
            const cid = btn.dataset.customerLogId || '';
            btn.disabled = true;
            const origText = btn.textContent;
            btn.textContent = '⏳ 불러오는 중...';
            let transcript = '';
            let aiModel = '';
            // 사장님 2026-05-24 — 자물쇠 모드: customer_log_id 가 있으면 id 로 직접 조회.
            // 다른 회차 transcript 와 혼선 0%. 없으면 phone+ts 매칭 (옛 데이터 호환).
            if (cid) {
                const item = await fetchTranscriptById(cid);
                transcript = item?.transcript || '';
                aiModel = item?.ai_model || '';
            } else {
                const items = await fetchTranscriptsByPhone(phone);
                const match = _findTranscriptByTimestamp(items, ts);
                transcript = match?.transcript || '';
                aiModel = match?.ai_model || '';
            }
            btn.disabled = false;
            btn.textContent = origText;
            openTranscriptModal({ ts, round, transcript, aiModel });
        });
    });
}

/* 전문보기 별도 큰 모달 (사장님 2026-05-20 — 인라인 → 새 모달 분리). */
function openTranscriptModal({ ts, round, transcript, aiModel }) {
    document.querySelectorAll('.transcript-modal').forEach(m => m.remove());
    const md = document.createElement('div');
    md.className = 'modal-backdrop transcript-modal';
    md.style.zIndex = '400';
    const body = transcript
        ? `<div class="transcript-modal-text">${escapeHtml(transcript)}</div>`
        : `<div class="transcript-modal-empty">해당 회차의 전문이 저장되어 있지 않습니다.<br><small style="color:#a3a39a;font-size:12.5px;">옛 데이터(STT 도입 이전) 또는 customer_log row 누락 케이스.</small></div>`;
    const sub = [
        ts ? `🕐 ${escapeHtml(ts)}` : '',
        round ? `${escapeHtml(round)}회차` : '',
        aiModel ? `🤖 ${escapeHtml(aiModel)}` : '',
    ].filter(Boolean).join(' · ');
    md.innerHTML = `
        <div class="modal-panel transcript-modal-panel">
            <header class="modal-header">
                <div>
                    <h2>대화내용 전문</h2>
                    <p class="modal-subtitle">${sub}</p>
                </div>
            </header>
            <div class="modal-body transcript-modal-body">${body}</div>
            <footer class="modal-footer">
                <button class="tiny-btn" type="button" data-close>닫기</button>
                ${transcript ? '<button class="tiny-btn primary" type="button" data-copy>복사</button>' : ''}
            </footer>
        </div>`;
    document.body.appendChild(md);
    const close = () => md.remove();
    md.querySelector('[data-close]').addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
    const copyBtn = md.querySelector('[data-copy]');
    if (copyBtn) {
        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(transcript);
                copyBtn.textContent = '✓ 복사됨';
                setTimeout(() => { copyBtn.textContent = '복사'; }, 1500);
            } catch {
                copyBtn.textContent = '복사 실패';
                setTimeout(() => { copyBtn.textContent = '복사'; }, 1500);
            }
        });
    }
    document.addEventListener('keydown', function onEsc(e) {
        if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onEsc); }
    });
}

function closeRowDetailModal() {
    document.querySelectorAll('.row-detail-modal').forEach(m => m.remove());
}

function customerCustomRender(f, defaults) {
    const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
    if (f.type === 'manage_switch') {
        const checked = defaults.managed !== false ? 'checked' : '';
        return `<div class="modal-row">${lbl}<div class="row-control"><label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#4f4943;font-weight:400;cursor:pointer;margin:0;">
            <input type="checkbox" data-field="managed" ${checked} style="width:auto;accent-color:#c8362c;"> 관리 대상 (체크 해제 시 비관리)
        </label></div></div>`;
    }
    if (f.type === 'call_count') {
        // 통화수 자동 계산 — 사용자 입력 X. defaults 값 그대로 유지 (data-readonly).
        const v = defaults.call_count;
        const display = (v !== undefined && v !== null && v !== '')
            ? (escapeHtml(String(v)) + '회')
            : '<i style="color:#a3a39a;">저장 시 자동 계산</i>';
        return `<div class="modal-row">${lbl}<div class="row-control"><span class="row-static" data-field="call_count" data-readonly>${display}</span></div></div>`;
    }
    return null;
}

async function updateRowField(id, field, value) {
    try {
        await api('ledger-records', { method: 'PATCH', body: { id, data: { [field]: value } } });
        const r = records.find(x => x.id === id);
        if (r) { r.data = r.data || {}; r.data[field] = value; }
    } catch (e) {
        showError('저장 실패: ' + e.message);
        await loadRecords();
    }
}

async function toggleManaged(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const next = !r.data?.managed;
    await updateRowField(id, 'managed', next);
    renderRecords();
}

async function deleteRow(id) {
    if (!confirm('이 행을 삭제할까요?')) return;
    try {
        await api('ledger-records', { method: 'DELETE', body: { id } });
        await loadRecords();
    } catch (e) { showError('삭제 실패: ' + e.message); }
}

/* ============== 엑셀 다운로드 / 업로드 ============== */
async function exportGroup(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const rows = records.filter(r => r.groupId === gid);
    if (rows.length === 0) {
        if (!confirm(`"${g.name}" 그룹에 행이 없습니다. 빈 양식만 다운로드할까요?`)) return;
    }
    try {
        await exportRecordsToExcel({
            sheets: [{ name: g.name, fields, rows }],
            fileName: `고객관리대장_${g.name}_${todayStamp()}.xlsx`,
        });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

async function exportAllGroups() {
    if (groups.length === 0) { alert('내보낼 그룹이 없습니다.'); return; }
    const sheets = groups.map(g => ({
        name: g.name,
        fields: getEffectiveFields(g, DEFAULT_FIELDS),
        rows: records.filter(r => r.groupId === g.id),
    }));
    try {
        await exportRecordsToExcel({ sheets, fileName: `고객관리대장_전체_${todayStamp()}.xlsx` });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

async function importToGroup(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const file = await pickExcelFile();
    if (!file) return;
    let parsed;
    try { parsed = await parseExcelFile(file); }
    catch (e) { showError('엑셀 파일을 읽지 못했습니다: ' + e.message); return; }
    if (!parsed.length || !parsed[0].headers.length) { alert('빈 파일이거나 헤더 행이 없습니다.'); return; }
    const sheet = parsed[0];   // 단일 시트만 사용 (첫 시트)
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const suggested = suggestFieldMapping(sheet.headers, fields, FIELD_SYNONYMS);

    openImportPreviewModal({
        title: `"${g.name}" 에 가져오기`,
        sheetName: sheet.name,
        headers: sheet.headers,
        rows: sheet.rows,
        skippedBlank: sheet.skippedBlank || 0,
        fields,
        fallbackKey: FALLBACK_FIELD_KEY,
        suggested,
        onConfirm: async (mappedRows, finalMapping, ctx) => {
            const newIds = await applyImportRows(gid, mappedRows, ctx);
            saveImportSession(PAGE_TYPE, gid, { sheet, mapping: finalMapping, recordIds: newIds });
            await loadRecords();
        },
    });
}

async function applyImportRows(gid, mappedRows, ctx) {
    const ids = [];
    const total = mappedRows.length;
    ctx?.setProgress?.(0, total);
    for (let i = 0; i < mappedRows.length; i++) {
        const data = mappedRows[i];
        if (data.managed === undefined || data.managed === '') data.managed = true;
        const res = await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'excel' } });
        if (res?.id) ids.push(res.id);
        ctx?.setProgress?.(i + 1, total);
    }
    return ids;
}

async function reopenImportSession(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const sess = loadImportSession(PAGE_TYPE, gid);
    if (!sess || !sess.sheet) { alert('저장된 가져오기 기록이 없습니다.'); return; }
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const mapping = (Array.isArray(sess.mapping) && sess.mapping.length === sess.sheet.headers.length)
        ? sess.mapping : suggestFieldMapping(sess.sheet.headers, fields, FIELD_SYNONYMS);
    openImportPreviewModal({
        title: `"${g.name}" 매핑 재수정`,
        sheetName: sess.sheet.name,
        headers: sess.sheet.headers,
        rows: sess.sheet.rows,
        fields,
        fallbackKey: FALLBACK_FIELD_KEY,
        suggested: mapping,
        confirmLabel: `${(sess.sheet.rows || []).length}건 다시 적용`,
        extraDanger: {
            label: '이 가져오기 기록 폐기',
            onClick: async () => {
                if (!confirm('가져오기 기록을 폐기합니다. (이미 추가된 행은 그대로 남고 매핑 수정 버튼만 사라집니다.) 진행할까요?')) throw new Error('취소됨');
                clearImportSession(PAGE_TYPE, gid);
                renderRecords();
            },
        },
        onConfirm: async (mappedRows, finalMapping, ctx) => {
            const oldIds = Array.isArray(sess.recordIds) ? sess.recordIds.filter(Boolean) : [];
            if (oldIds.length) {
                ctx?.setProgress?.(0, mappedRows.length, `이전 ${oldIds.length}건 삭제 중...`);
                try { await api('ledger-records-bulk', { method: 'POST', body: { ids: oldIds } }); }
                catch (e) { /* 이미 일부 삭제됐을 수 있음 — 진행 */ }
            }
            const newIds = await applyImportRows(gid, mappedRows, ctx);
            saveImportSession(PAGE_TYPE, gid, { sheet: sess.sheet, mapping: finalMapping, recordIds: newIds });
            await loadRecords();
        },
    });
}

function todayStamp() {
    const d = new Date();
    return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
}

async function bulkDelete() {
    const ids = [...selectedIds];
    if (ids.length === 0) return;
    if (!confirm(`선택한 ${ids.length}건을 삭제합니다. 진행할까요?`)) return;
    try {
        await api('ledger-records-bulk', { method: 'POST', body: { ids } });
        selectedIds.clear();
        await loadRecords();
    } catch (e) { showError('일괄 삭제 실패: ' + e.message); }
}

function updateBulkBar() {
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = selectedIds.size;
    bar.classList.toggle('active', selectedIds.size > 0);
}

/* ============== Utils ============== */
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
// 옛 데이터의 ━ 구분선 제거 (저장된 raw 는 그대로, 표시만 정리).
// 상세 모달용 — 회차 header 마커는 유지.
function sanitizeContent(s) {
    return String(s ?? '').replace(/[━─]+/g, '').replace(/ {2,}/g, ' ').replace(/\n{4,}/g, '\n\n\n');
}
// 셀 미리보기용 — 날짜/회차 header 라인까지 제거하여 요약 내용만 노출.
// "📞 2026-05-18 19:10:17 통화 (23회차)" 같은 라인은 미리보기에서 불필요
// (날짜는 별도 컬럼, cell 폭이 좁으면 header 만 wrap 되어 summary 안 보임).
function previewContent(s) {
    return String(s ?? '')
        .replace(/^[ \t]*📞[^\n]*\n+/gm, '')
        .replace(/^[ \t]*📝[^\n]*\n+/gm, '')
        .replace(/[━─]+/g, '')
        .replace(/ {2,}/g, ' ')
        .replace(/\n{2,}/g, '\n')
        .trim();
}
function escapeAttr(s) { return String(s ?? '').replace(/"/g, '&quot;'); }
function formatPhoneDisplay(p) {
    const digits = String(p ?? '').replace(/\D/g, '');
    if (digits.length === 11) return digits.replace(/(\d{3})(\d{4})(\d{4})/, '$1-$2-$3');
    if (digits.length === 10) return digits.replace(/(\d{2,3})(\d{3,4})(\d{4})/, '$1-$2-$3');
    if (digits.length === 8)  return digits.replace(/(\d{4})(\d{4})/, '$1-$2');
    return String(p ?? '');
}
function truncateName(n, max = 4) {
    const s = String(n ?? '');
    return s.length > max ? s.slice(0, max) + '…' : s;
}
function nowHHMM() {
    const d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}
function showError(msg) {
    console.error(msg);
    const c = document.getElementById('content');
    if (c) c.insertAdjacentHTML('afterbegin', `<div style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:13px;">${escapeHtml(msg)}</div>`);
}

/* ============== Solapi 미연동 안내 모달 — 60대+ 사용자 가독성 우선 ============== */
function showSolapiSetupGuide() {
    document.querySelectorAll('.solapi-guide-modal').forEach(m => m.remove());
    const md = document.createElement('div');
    md.className = 'modal-backdrop solapi-guide-modal';
    // 인라인 z-index 박지 않음 — .modal-backdrop 의 1000 이 그대로 적용돼야 mobile bottom nav(900) 위에 표시됨.
    md.innerHTML = `
        <div class="modal-panel" style="max-width:560px;">
            <header class="modal-header" style="display:flex;align-items:center;gap:10px;">
                <div>
                    <h2 style="font-size:20px;">문자 발송 준비가 필요해요</h2>
                    <p class="modal-subtitle" style="font-size:14px;line-height:1.55;color:#4f4943;margin-top:6px;">
                        문자 발송에는 <b>솔라피(Solapi) 계정</b>의 API 키가 필요합니다.<br>
                        아래 순서대로 한 번만 등록하시면 그 다음부터는 바로 발송할 수 있어요.
                    </p>
                </div>
            </header>
            <div class="modal-body" style="padding:6px 22px 4px;">
                <ol style="padding-left:22px;margin:14px 0;line-height:1.85;font-size:15px;color:#0e0d0c;">
                    <li style="margin-bottom:14px;">
                        <b>솔라피 회원가입</b><br>
                        <a href="https://solapi.com/" target="_blank" rel="noopener"
                           style="color:#c8362c;font-weight:700;text-decoration:underline;font-size:15px;">
                            👉 https://solapi.com/ 바로가기
                        </a>
                    </li>
                    <li style="margin-bottom:14px;">
                        <b>로그인 후 API 키 발급 페이지로 이동</b><br>
                        <a href="https://console.solapi.com/credentials" target="_blank" rel="noopener"
                           style="color:#c8362c;font-weight:700;text-decoration:underline;font-size:15px;">
                            👉 https://console.solapi.com/credentials 바로가기
                        </a>
                    </li>
                    <li style="margin-bottom:14px;">
                        <b>두 가지 항목을 복사</b>
                        <div style="background:#fbf7ef;border-radius:8px;padding:12px 14px;margin-top:8px;font-size:14.5px;line-height:1.75;">
                            • <b>API KEY</b><br>
                            • <b>API SECRET</b>
                            <span style="color:#7a1812;font-size:13px;">
                                (SECRET 은 <b>조회 버튼</b>을 누르셔야 보입니다)
                            </span>
                        </div>
                    </li>
                    <li>
                        <b>영맨 사이트 [내 정보 &gt; 문자설정] 으로 돌아와서 붙여넣기 → 저장</b>
                    </li>
                </ol>
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;margin:6px 0 14px;font-size:14px;line-height:1.7;color:#7c2d12;">
                    💡 <b>요금 안내</b><br>
                    문자 요금은 <b>사용자의 Solapi 계정 잔액</b>에서 차감됩니다.<br>
                    솔라피에서 <b>문자 요금 충전 후</b> 사용하실 수 있습니다.
                </div>
            </div>
            <footer class="modal-footer" style="display:flex;gap:8px;padding:16px 22px 18px;">
                <button type="button" class="tiny-btn" data-cancel
                        style="font-size:14px;padding:10px 16px;">나중에 할게요</button>
                <button type="button" class="tiny-btn primary" data-goto-sms
                        style="font-size:14.5px;padding:10px 18px;font-weight:700;">
                    내 정보 &gt; 문자설정 으로 이동
                </button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);
    const close = () => md.remove();
    md.querySelector('[data-cancel]').addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
    md.querySelector('[data-goto-sms]').addEventListener('click', () => {
        window.location.href = 'profile.html?tab=sms';
    });
}

/* ============== SMS 단체 발송 모달 (선택 고객들에게) ============== */
async function openSmsModal() {
    if (selectedIds.size === 0) { alert('먼저 보낼 고객을 체크해 주세요.'); return; }

    // 1) 자격증명 확인 — 미연동이면 안내 + 설정 페이지로 이동
    let cred;
    try { cred = await apiRequest('sms-credentials'); }
    catch (e) { alert('문자 설정을 불러오지 못했습니다: ' + (e.message || e)); return; }
    if (!cred?.configured) {
        showSolapiSetupGuide();
        return;
    }

    const ids = [...selectedIds];

    // 선택된 고객 → {name, phone} 리스트
    // record 데이터는 r.data 안에 있고 사용자가 양식을 커스텀할 수 있으므로
    // 그룹의 effective fields (type='tel' = 전화번호) + 흔한 키 fallback 으로 추출.
    const selectedRecords = records.filter(r => ids.includes(r.id));
    const recipients = selectedRecords.map(r => {
        const d = r.data || {};
        const group = groups.find(g => g.id === r.groupId);
        const fields = group ? getEffectiveFields(group, DEFAULT_FIELDS) : DEFAULT_FIELDS;

        // 이름 — customer/name 키 또는 label 매칭 우선
        let name = '';
        for (const f of fields) {
            const lbl = String(f.label || '');
            if (f.key === 'customer' || f.key === 'name'
                || /이름|고객명|성명|의뢰인/.test(lbl)) {
                if (d[f.key]) { name = String(d[f.key]); break; }
            }
        }
        if (!name) name = d.customer || d.name || d['이름'] || d['고객명'] || '이름없음';

        // 전화번호 — type='tel' 필드 우선, 없으면 흔한 키 fallback
        let phoneRaw = '';
        for (const f of fields) {
            if (f.type === 'tel' && d[f.key]) { phoneRaw = String(d[f.key]); break; }
        }
        if (!phoneRaw) phoneRaw = d.phone || d.tel || d.mobile || d.hp || d['전화번호'] || d['연락처'] || d['휴대폰'] || '';

        return {
            id: r.id,
            name: String(name).trim() || '이름없음',
            phone: formatPhoneDisplay(phoneRaw),
            rawPhone: String(phoneRaw).trim()
        };
    });
    // 모달 안에서만 임시 제외된 수신자 id 집합 — 발송 시 제외, selectedIds(글로벌) 는 안 건드림.
    const removedIds = new Set();
    const senderPhoneFmt = formatPhoneDisplay(cred.senderPhone || '');

    // 초기 chip/dropdown/list 는 빈 placeholder — mount 후 renderRecipientPanel() 이 채움.
    const chipsHtml = '';
    const moreHtml = '';
    const rpListHtml = '';

    const md = document.createElement('div');
    md.className = 'modal-backdrop sms-modal';
    md.style.zIndex = '300';
    md.innerHTML = `
        <div class="modal-panel sms-phone-panel">
            <!-- 발송 대기 로딩 overlay (modal-panel.is-sending 일 때만 표시) -->
            <div class="sms-loading" aria-hidden="true">
                <div class="ring"></div>
                <p>문자 발송 중…<small>잠시만 기다려 주세요.</small></p>
            </div>

            <header class="modal-header">
                <div>
                    <h2>📨 문자 단체 발송</h2>
                    <p class="modal-subtitle">선택한 ${ids.length}명의 고객에게 문자를 보냅니다.</p>
                </div>
            </header>
            <div class="modal-body sms-modal-body">
                <div class="sms-notice">
                    <b>안내</b><br>
                    · 문자 요금은 본인의 <b>Solapi 계정 잔액</b>에서 차감됩니다.<br>
                    · 발신번호: <code>${escapeHtml(senderPhoneFmt || '미등록')}</code><br>
                    · 광고성 문자는 <b>수신동의 고객에게만</b> 발송해야 하며, 본문에 <b>[수신거부]</b> 문구가 필요합니다.
                </div>

                <!-- 메시지 타입 배지 (SMS / LMS / MMS) — 큰 글씨로 뚜렷하게 -->
                <div class="sms-type-bar">
                    <span class="sms-type-pill" data-type="SMS" id="smsTypePill">SMS · 단문</span>
                    <span class="sms-type-detail">
                        본문 <b id="smsBytes">0</b> / 90 바이트
                        <span id="smsTypeReason"></span>
                    </span>
                </div>

                <!-- 좌측: 수신자 풀 리스트  /  우측: 스마트폰 -->
                <div class="sms-layout">
                    <aside class="sms-recipient-panel" aria-label="받는사람 목록">
                        <header class="sms-rp-head">
                            <span>📞 받는사람</span>
                            <b class="rp-count">${recipients.length}명</b>
                        </header>
                        <ul class="sms-rp-list">${rpListHtml}</ul>
                    </aside>

                    <div class="sms-phone-side">
                    <!-- Solapi 충전 잔액 카드 — 폰 미리보기 바로 위 -->
                    <div class="sms-balance-card loading" data-balance-card>
                        <div>
                            <div class="label">💰 Solapi 충전 잔액</div>
                            <div class="value"><span data-balance-value>—</span><small>원</small></div>
                        </div>
                        <div class="sub" data-balance-sub>잔액을 불러오는 중…</div>
                        <a href="https://console.solapi.com/dashboard" target="_blank" rel="noopener" class="recharge">Solapi 대시보드 →</a>
                    </div>

                    <div class="sms-phone" aria-label="문자 미리보기">
                        <div class="sms-phone-screen">
                            <div class="sms-phone-statusbar">
                                <span>${nowHHMM()}</span>
                                <span class="status-r">
                                    <svg width="16" height="10" viewBox="0 0 16 10" aria-hidden="true">
                                        <path d="M2 8.5l1.5-1.5 M5 7l2-2 M8.5 5.5L11 3 M12 4l2.5-2.5" stroke="#0e0d0c" stroke-width="1.4" fill="none" stroke-linecap="round"/>
                                    </svg>
                                    <svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
                                        <rect x="1" y="1" width="17" height="8" rx="2" fill="none" stroke="#0e0d0c" stroke-width="1.2" opacity=".75"/>
                                        <rect x="19" y="3.5" width="2" height="3" rx=".6" fill="#0e0d0c" opacity=".75"/>
                                        <rect x="2.5" y="2.5" width="13" height="5" rx="1" fill="#34a853"/>
                                    </svg>
                                </span>
                            </div>
                            <div class="sms-phone-header">
                                <h3>새 메시지</h3>
                                <span class="sms-phone-type-mini" data-type="SMS" id="smsPhoneTypeMini">SMS</span>
                            </div>
                            <div class="sms-phone-recipients">
                                <span class="sms-phone-recipients-label">받는사람</span>
                                ${chipsHtml}
                                ${moreHtml}
                            </div>
                            <div class="sms-phone-thread" id="smsThread">
                                <div class="sms-phone-time">방금</div>
                                <div class="sms-phone-bubble placeholder" id="smsBubble">메시지를 입력하면 여기에 미리보기가 표시됩니다.</div>
                            </div>
                            <div class="sms-phone-inputbar">
                                <textarea id="smsText" class="sms-phone-input" rows="1" maxlength="2000"
                                    placeholder="메시지 작성..."></textarea>
                                <button type="button" class="sms-phone-send" disabled title="미리보기 갱신" aria-label="미리보기">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- 사진 첨부 (drag & drop) -->
                <div class="sms-attach-zone" id="smsAttachZone" role="button" tabindex="0" aria-label="이미지 첨부 (클릭 또는 드래그)">
                    <input type="file" id="smsAttachInput" accept="image/jpeg,image/png,image/gif" hidden>
                    <div class="sms-attach-empty" data-empty>
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="3"/>
                            <circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
                            <path d="M21 15l-5-5-5 5-3-3-5 5"/>
                        </svg>
                        <div>
                            <p>이미지를 끌어다 놓거나 클릭해서 첨부 (MMS)</p>
                            <small>JPG / PNG / GIF · 최대 200KB · 이미지 첨부 시 MMS 로 전환</small>
                        </div>
                    </div>
                    <div class="sms-attach-preview" data-preview hidden>
                        <img id="smsAttachImg" alt="">
                        <div class="sms-attach-info">
                            <b id="smsAttachName"></b>
                            <small id="smsAttachSize"></small>
                        </div>
                        <button type="button" class="sms-attach-remove" data-attach-remove>제거</button>
                    </div>
                </div>

                <div class="sms-phone-helper">
                    <span><b id="smsLen">0</b> 자 · <b id="smsBytes2">0</b> 바이트 (한글 2 / 영문 1)</span>
                    <span>선택 고객 <b>${ids.length}</b>명 · 발송 건수는 동의/번호 확인 후 결정</span>
                </div>
                <p class="form-help error" data-error style="margin:10px 0 0;display:none;"></p>
            </div>
            <footer class="modal-footer">
                <button class="tiny-btn" type="button" data-cancel>취소</button>
                <button class="tiny-btn primary" type="button" data-send>📨 발송하기</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);

    const modalPanel = md.querySelector('.modal-panel');
    const textarea = md.querySelector('#smsText');
    const lenEl    = md.querySelector('#smsLen');
    const bytesEl  = md.querySelector('#smsBytes');
    const bytes2El = md.querySelector('#smsBytes2');
    const typePill = md.querySelector('#smsTypePill');
    const typeMini = md.querySelector('#smsPhoneTypeMini');
    const typeReasonEl = md.querySelector('#smsTypeReason');
    const errEl    = md.querySelector('[data-error]');
    const sendBtn  = md.querySelector('[data-send]');
    const cancelBtn = md.querySelector('[data-cancel]');
    const bubbleEl  = md.querySelector('#smsBubble');
    const previewSendBtn = md.querySelector('.sms-phone-send');
    const moreBtn   = md.querySelector('[data-more]');
    const moreList  = md.querySelector('[data-more-list]');
    const attachZone = md.querySelector('#smsAttachZone');
    const attachInput = md.querySelector('#smsAttachInput');
    const attachEmpty = md.querySelector('[data-empty]');
    const attachPreview = md.querySelector('[data-preview]');
    const attachImg   = md.querySelector('#smsAttachImg');
    const attachName  = md.querySelector('#smsAttachName');
    const attachSize  = md.querySelector('#smsAttachSize');
    const attachRemove = md.querySelector('[data-attach-remove]');
    const balCard     = md.querySelector('[data-balance-card]');
    const balValueEl  = md.querySelector('[data-balance-value]');
    const balSubEl    = md.querySelector('[data-balance-sub]');

    let attachedImage = null;   // { name, base64, sizeKB, dataUrl, width, height }

    // ===== 수신자 panel/chip/dropdown 렌더링 =====
    // removedIds 변화 시 호출 → 모달 안의 모든 수신자 표시를 동기화한다.
    const rpListEl = md.querySelector('.sms-rp-list');
    const rpCountEl = md.querySelector('.sms-rp-head .rp-count');
    const recipientsBar = md.querySelector('.sms-phone-recipients');
    const recipientsLabel = recipientsBar?.querySelector('.sms-phone-recipients-label');
    const modalSubtitle = md.querySelector('.modal-subtitle');

    function renderRecipientPanel() {
        const active = recipients.filter(r => !removedIds.has(r.id));
        const N = active.length;

        // 1) 좌측 list — 각 행에 × 버튼
        if (N === 0) {
            rpListEl.innerHTML = '<li class="rp-empty">제외할 수신자가 없습니다.<br>모든 수신자를 제거했습니다.</li>';
        } else {
            rpListEl.innerHTML = active.map(r => `
                <li data-rid="${r.id}">
                    <span class="rp-nm" title="${escapeAttr(r.name)}">${escapeHtml(r.name)}</span>
                    <span class="rp-ph ${r.rawPhone ? '' : 'muted'}" title="${escapeAttr(r.phone || '번호없음')}">${escapeHtml(r.phone || '번호없음')}</span>
                    <button type="button" class="rp-remove" data-remove-id="${r.id}" title="이 수신자 제외" aria-label="제외">×</button>
                </li>
            `).join('');
        }

        // 2) count + subtitle
        if (rpCountEl) rpCountEl.textContent = `${N}명`;
        if (modalSubtitle) modalSubtitle.textContent = `선택한 ${N}명의 고객에게 문자를 보냅니다.`;

        // 3) 폰 헤더 chip (5개 + more) — 처음부터 다시 그림
        if (recipientsBar && recipientsLabel) {
            // label 만 유지하고 그 뒤를 다 비운 후 다시 채움
            // (label 다음 노드들 제거)
            let n = recipientsLabel.nextSibling;
            while (n) {
                const next = n.nextSibling;
                recipientsBar.removeChild(n);
                n = next;
            }
            const visible = active.slice(0, 5);
            const extra = active.slice(5);
            const chipsHtml = visible.map(r => `
                <span class="sms-recipient-chip" title="${escapeAttr(r.name)} · ${escapeAttr(r.phone)}">
                    <span class="chip-name">${escapeHtml(truncateName(r.name))}</span>${escapeHtml(r.phone || '번호없음')}
                </span>
            `).join('');
            const moreHtml = extra.length ? `
                <span class="sms-recipient-more-wrap">
                    <button type="button" class="sms-recipient-more" data-more>+${extra.length}명</button>
                    <div class="sms-recipient-dropdown" data-more-list>
                        <div class="sms-recipient-dropdown-head">전체 수신자 (${active.length}명)</div>
                        ${active.map(r => `
                            <div class="sms-recipient-dropdown-item">
                                <span class="nm">${escapeHtml(r.name)}</span>
                                <span class="ph">${escapeHtml(r.phone || '번호없음')}</span>
                            </div>
                        `).join('')}
                    </div>
                </span>
            ` : '';
            recipientsBar.insertAdjacentHTML('beforeend', chipsHtml + moreHtml);
            // more 버튼 click → dropdown toggle (재바인딩)
            const newMoreBtn = recipientsBar.querySelector('[data-more]');
            const newMoreList = recipientsBar.querySelector('[data-more-list]');
            if (newMoreBtn && newMoreList) {
                newMoreBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    newMoreList.classList.toggle('open');
                });
            }
        }

        // 4) 발송 버튼 활성/비활성 — 수신자 0명이면 비활성
        sendBtn.disabled = (N === 0);
        sendBtn.textContent = (N === 0) ? '수신자가 없습니다' : '📨 발송하기';
    }

    // list 의 × 버튼 click 위임
    rpListEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-id]');
        if (!btn) return;
        const rid = Number(btn.dataset.removeId);
        if (!isNaN(rid)) {
            removedIds.add(rid);
            renderRecipientPanel();
        }
    });

    renderRecipientPanel();   // 첫 렌더

    // Solapi 잔액 fetch — 비동기, UI 막지 않음
    (async () => {
        try {
            const token = await getAccessTokenForSms();
            const resp = await fetch('sms/balance.php', {
                method: 'GET',
                headers: { 'Authorization': 'Bearer ' + token },
            });
            const data = await resp.json().catch(() => ({}));
            balCard.classList.remove('loading');
            console.log('[sms balance]', resp.status, data);   // 디버깅
            if (data?.ok && typeof data.balance === 'number') {
                const won = Math.round(data.balance);
                balValueEl.textContent = won.toLocaleString('ko-KR');
                const pointStr = (typeof data.point === 'number' && data.point > 0)
                    ? ` · 포인트 ${Math.round(data.point).toLocaleString('ko-KR')}원` : '';
                balSubEl.textContent = `발송 가능${pointStr}`;
                if (won < 100) {
                    balCard.classList.add('err');
                    balSubEl.textContent = '⚠️ 잔액 부족 — 충전 후 발송하세요';
                }
            } else {
                balCard.classList.add('err');
                balValueEl.textContent = '?';
                // Solapi 가 준 에러 사유를 그대로 보여줌 — 사용자가 진단 가능
                const reason = data?.error || data?.reason || `HTTP ${resp.status}`;
                balSubEl.textContent = '잔액 조회 실패: ' + reason;
                balSubEl.style.fontSize = '11px';
                balSubEl.style.maxWidth = '60%';
            }
        } catch (e) {
            balCard.classList.remove('loading');
            balCard.classList.add('err');
            balValueEl.textContent = '?';
            balSubEl.textContent = '잔액 조회 실패: ' + (e.message || e);
            console.error('[sms balance]', e);
        }
    })();

    const close = () => md.remove();
    cancelBtn.addEventListener('click', close);
    md.addEventListener('click', (e) => {
        if (e.target === md) close();
        // dropdown 외부 클릭 시 닫힘 — renderRecipientPanel 이 매번 새 dropdown 만들므로 fresh fetch.
        const currentList = md.querySelector('[data-more-list]');
        const currentBtn  = md.querySelector('[data-more]');
        if (currentList?.classList.contains('open') &&
            !currentList.contains(e.target) && e.target !== currentBtn) {
            currentList.classList.remove('open');
        }
    });

    // ===== 메시지 타입 + bytes 계산 =====
    const computeMessageType = () => {
        // 한글 2바이트 (EUC-KR 기준), 영문/숫자/공백 1바이트
        const text = textarea.value;
        let bytes = 0;
        for (const ch of text) {
            bytes += ch.charCodeAt(0) > 0x7F ? 2 : 1;
        }
        bytesEl.textContent = bytes;
        bytes2El.textContent = bytes;
        lenEl.textContent = text.length;

        let type = 'SMS';
        let reason = '';
        if (attachedImage) {
            type = 'MMS';
            reason = ' · 이미지 첨부됨';
        } else if (bytes > 90) {
            type = 'LMS';
            reason = ` · 90바이트 초과 (${bytes - 90}바이트 초과)`;
        }
        const labels = {
            SMS: 'SMS · 단문',
            LMS: 'LMS · 장문',
            MMS: 'MMS · 사진+장문',
        };
        typePill.textContent = labels[type];
        typePill.setAttribute('data-type', type);
        typeMini.textContent = type;
        typeMini.setAttribute('data-type', type);
        typeReasonEl.innerHTML = reason
            ? `<span class="${type === 'MMS' ? 'bytes-mms' : 'bytes-warn'}">${escapeHtml(reason)}</span>`
            : '';
    };

    const updatePreview = () => {
        const v = textarea.value;
        // textarea auto-grow
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 140) + 'px';
        // bubble — 이미지 + 텍스트 둘 다 반영
        const hasText  = !!v.trim();
        const hasImage = !!attachedImage;
        bubbleEl.classList.remove('placeholder', 'with-image');
        if (hasImage && hasText) {
            bubbleEl.classList.add('with-image');
            bubbleEl.innerHTML = `<img src="${escapeAttr(attachedImage.dataUrl)}" alt=""><div class="bubble-text">${escapeHtml(v)}</div>`;
            previewSendBtn.disabled = false;
        } else if (hasImage) {
            bubbleEl.classList.add('with-image');
            bubbleEl.innerHTML = `<img src="${escapeAttr(attachedImage.dataUrl)}" alt="">`;
            previewSendBtn.disabled = false;
        } else if (hasText) {
            bubbleEl.textContent = v;
            previewSendBtn.disabled = false;
        } else {
            bubbleEl.classList.add('placeholder');
            bubbleEl.textContent = '메시지를 입력하면 여기에 미리보기가 표시됩니다.';
            previewSendBtn.disabled = true;
        }
        computeMessageType();
    };
    textarea.addEventListener('input', updatePreview);
    textarea.focus();

    // ===== 이미지 첨부 (Solapi MMS 제약 검증) =====
    const MAX_IMAGE_BYTES = 200 * 1024;     // 200KB
    const MAX_IMAGE_WIDTH  = 1500;          // Solapi MMS 권장 max
    const MAX_IMAGE_HEIGHT = 1440;

    const showAttachError = (msg) => {
        errEl.style.color = '#c8362c';
        errEl.textContent = msg;
        errEl.style.display = '';
        // 첨부 zone 도 흔들기로 강조
        try {
            attachZone.animate(
                [{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }],
                { duration: 280 }
            );
        } catch {}
    };

    const setAttachedImage = async (file) => {
        if (!file) return;

        // 1) MIME 형식
        if (!/^image\/(jpe?g|png|gif)$/i.test(file.type)) {
            const ext = (file.name || '').split('.').pop()?.toLowerCase() || '알 수 없음';
            showAttachError(`이 이미지는 첨부할 수 없습니다. JPG / PNG / GIF 만 가능합니다. (현재: ${ext})`);
            return;
        }

        // 2) 파일 크기
        if (file.size > MAX_IMAGE_BYTES) {
            const kb = (file.size / 1024).toFixed(0);
            showAttachError(`이미지가 너무 큽니다 (${kb} KB). MMS 첨부는 200 KB 이하만 가능합니다. 압축하거나 작은 이미지로 시도해주세요.`);
            return;
        }

        // 3) data URL 로 읽기
        const dataUrl = await new Promise((resolve) => {
            const fr = new FileReader();
            fr.onload  = () => resolve(fr.result);
            fr.onerror = () => resolve(null);
            fr.readAsDataURL(file);
        });
        if (!dataUrl || typeof dataUrl !== 'string') {
            showAttachError('이미지를 읽을 수 없습니다. 다른 이미지로 시도해주세요.');
            return;
        }

        // 4) 해상도 — Image 로드해서 width/height 검사
        const dim = await new Promise((resolve) => {
            const img = new Image();
            img.onload  = () => resolve({ w: img.naturalWidth, h: img.naturalHeight });
            img.onerror = () => resolve(null);
            img.src = dataUrl;
        });
        if (!dim) {
            showAttachError('이미지 파일이 손상되었거나 표시할 수 없습니다. 다른 이미지로 시도해주세요.');
            return;
        }
        if (dim.w > MAX_IMAGE_WIDTH || dim.h > MAX_IMAGE_HEIGHT) {
            showAttachError(`이미지 해상도가 너무 큽니다 (${dim.w}×${dim.h}px). MMS 첨부는 ${MAX_IMAGE_WIDTH}×${MAX_IMAGE_HEIGHT}px 이하만 가능합니다. 리사이즈 후 다시 시도해주세요.`);
            return;
        }

        // 통과 — 적용
        errEl.style.display = 'none';
        const base64 = String(dataUrl).split(',')[1] || '';
        attachedImage = {
            name: file.name, sizeKB: file.size / 1024,
            dataUrl, base64, mime: file.type,
            width: dim.w, height: dim.h,
        };
        attachEmpty.hidden = true;
        attachPreview.hidden = false;
        attachImg.src = dataUrl;
        attachName.textContent = file.name;
        attachSize.textContent = `${attachedImage.sizeKB.toFixed(1)} KB · ${dim.w}×${dim.h} · ${file.type}`;
        updatePreview();
    };

    attachZone.addEventListener('click', (e) => {
        if (e.target.closest('[data-attach-remove]')) return;
        attachInput.click();
    });
    attachZone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); attachInput.click(); }
    });
    attachInput.addEventListener('change', (e) => {
        const f = e.target.files?.[0];
        if (f) setAttachedImage(f);
    });
    // drag & drop
    ['dragenter','dragover'].forEach(ev => attachZone.addEventListener(ev, (e) => {
        e.preventDefault(); e.stopPropagation();
        attachZone.classList.add('dragover');
    }));
    ['dragleave','drop'].forEach(ev => attachZone.addEventListener(ev, (e) => {
        e.preventDefault(); e.stopPropagation();
        if (ev !== 'drop') attachZone.classList.remove('dragover');
    }));
    attachZone.addEventListener('drop', (e) => {
        attachZone.classList.remove('dragover');
        const f = e.dataTransfer?.files?.[0];
        if (f) setAttachedImage(f);
    });
    // 페이지 전체 drag&drop 차단 (모달 밖에 떨어뜨려도 브라우저가 그림 열지 않도록 모달 떠 있는 동안)
    const blockOutside = (e) => { if (!attachZone.contains(e.target)) { e.preventDefault(); } };
    md.addEventListener('dragover', blockOutside);
    md.addEventListener('drop', blockOutside);

    attachRemove.addEventListener('click', (e) => {
        e.stopPropagation();
        attachedImage = null;
        attachInput.value = '';
        attachEmpty.hidden = false;
        attachPreview.hidden = true;
        attachImg.src = '';
        updatePreview();
    });

    computeMessageType();   // 초기 표시

    sendBtn.addEventListener('click', async () => {
        const text = textarea.value.trim();
        if (text === '' && !attachedImage) {
            errEl.textContent = '문자 내용 또는 이미지를 입력해주세요.';
            errEl.style.display = ''; return;
        }
        // 수신자 panel 에서 제외된 ids 빼고 실제 발송 대상 산출
        const activeIds = ids.filter(id => !removedIds.has(id));
        if (activeIds.length === 0) {
            errEl.textContent = '수신자가 없습니다. 좌측 목록에서 한 명 이상 유지해주세요.';
            errEl.style.display = ''; return;
        }
        if (!confirm('광고성 문자는 수신동의 고객에게만 발송해야 하며, 수신거부 문구가 필요합니다.\n\n계속 진행할까요?')) return;

        sendBtn.disabled = true;
        sendBtn.textContent = '발송 중…';
        errEl.style.display = 'none';
        modalPanel.classList.add('is-sending');   // 로딩 overlay 표시
        try {
            const res = await apiRequest('sms-credentials');   // 방어적
            if (!res?.configured) throw new Error('Solapi 가 해제되었습니다. 설정에서 다시 등록해 주세요.');
            const payload = { customer_ids: activeIds, message: text };
            if (attachedImage) {
                payload.image_base64 = attachedImage.base64;
                payload.image_name   = attachedImage.name;
                payload.image_mime   = attachedImage.mime;
            }
            const resp = await fetch('sms/send-bulk.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + (await getAccessTokenForSms()),
                },
                body: JSON.stringify(payload),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok) {
                const msg = data?.error || ('HTTP ' + resp.status);
                if (data?.action === 'open_settings') {
                    if (confirm(msg + '\n\n문자 설정 페이지로 이동할까요?')) {
                        window.location.href = 'profile.html?tab=sms';
                    }
                    return;
                }
                throw new Error(msg);
            }
            close();
            showSmsResultModal(data);
        } catch (e) {
            sendBtn.disabled = false;
            sendBtn.textContent = '📨 발송하기';
            errEl.textContent = '발송 실패: ' + (e.message || e);
            errEl.style.display = '';
        } finally {
            modalPanel.classList.remove('is-sending');   // 성공/실패 모두 overlay 해제
        }
    });
}

async function getAccessTokenForSms() {
    const { getAccessToken } = await import('./auth-shared.js?v=20260619-gp');
    return await getAccessToken();
}

function showSmsResultModal(data) {
    const md = document.createElement('div');
    md.className = 'modal-backdrop sms-modal';
    md.style.zIndex = '300';
    const failedRows = (data.failed || []).map(f => `
        <tr><td>${escapeHtml(f.phone || '')}</td><td>${escapeHtml(f.error || '')}</td></tr>
    `).join('');
    const skippedRows = (data.skipped || []).map(s => `
        <tr><td>#${s.customer_id}</td><td>${escapeHtml(s.reason || '')}</td></tr>
    `).join('');
    md.innerHTML = `
        <div class="modal-panel">
            <header class="modal-header">
                <div>
                    <h2>${data.dryRun ? '🧪 dry-run 결과' : '✅ 발송 결과'}</h2>
                    <p class="modal-subtitle">provider: ${escapeHtml(data.provider || '')}${data.dryRun ? ' · 실제 발송 안 됨' : ''}</p>
                </div>
            </header>
            <div class="modal-body sms-modal-body">
                <div class="sms-result-summary">
                    <div><b>선택</b> ${data.totalSelected || 0}명</div>
                    <div><b>동의 통과</b> ${data.afterConsent || 0}명</div>
                    <div><b>고유 번호</b> ${data.uniquePhones || 0}건</div>
                    <div class="ok"><b>성공</b> ${data.success || 0}건</div>
                    <div class="ng"><b>실패</b> ${(data.failed || []).length}건</div>
                </div>
                ${failedRows ? `
                    <h3 class="sms-table-head">실패한 번호 / 사유</h3>
                    <table class="sms-result-tbl"><thead><tr><th>번호</th><th>사유</th></tr></thead><tbody>${failedRows}</tbody></table>
                ` : ''}
                ${skippedRows ? `
                    <h3 class="sms-table-head">제외된 고객 / 사유</h3>
                    <table class="sms-result-tbl"><thead><tr><th>고객 ID</th><th>사유</th></tr></thead><tbody>${skippedRows}</tbody></table>
                ` : ''}
            </div>
            <footer class="modal-footer">
                <button class="tiny-btn primary" type="button" data-close>확인</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);
    const close = () => md.remove();
    md.querySelector('[data-close]').addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
}
