# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [Unreleased]

### Added

- PG 측 결제 취소가 확인된 시점에 활동 로그가 별도로 기록되도록 보강 — 운영자가 PG 응답 시각 / 취소 거래번호 등 사후 추적에 활용 가능.

## [1.0.0-beta.2] - 2026-05-16

### Security

- 결제 콜백 중복 처리 방어 추가 — 동일 거래번호로 콜백이 두 번 도착해도 결제완료/마일리지 적립 등이 중복 실행되지 않도록 멱등 응답 처리
- 결제 처리 중 오류 발생 시 결제 자동 취소 추가 — 카드 승인 후 후속 처리(금액 검증 등) 실패 시 PG 측 결제도 자동 취소하여 사용자 환불 누락 방지
- Windows 환경의 결제 모듈 호출 시 입력값 안전성 강화 — 위험 문자 사전 거부로 잠재적 명령 주입 차단

## [1.0.0-beta.1] - 2026-04-22

### Changed

- 플러그인 식별자를 `sirsoft-pay-nhnkcp`에서 `sirsoft-pay_nhnkcp`로 변경 — G7 코어가 권장하는 `vendor-name` 2-part 명명 규칙에 맞추기 위함
- 환불 메시지를 한국어/영어 다국어로 분리 — 운영 언어에 따라 자동 노출
- PG 프로바이더 표시명을 다국어 키로 분리 — 활성 언어팩으로 자동 보강되어 다른 PG 플러그인과 동일한 컨벤션으로 정렬

### Added

- 오픈 베타 릴리즈
- `sirsoft-pay_nhnkcp.payment.before_cancel` / `after_cancel` 액션 훅 — 외부 소비자가 결제 취소 지점에 본인인증 등 확장 로직을 붙일 수 있도록 확장점 제공
- PG 도메인 전용 예외 도입 — 외부 소비자가 NHN KCP 도메인 오류만 선택적으로 처리할 수 있도록 개선
