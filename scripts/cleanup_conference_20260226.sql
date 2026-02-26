-- =============================================================================
-- SCRIPT DE LIMPEZA: Conferência + Dados Antigos
-- Banco: erp_maiscapinhas
-- Data: 2026-02-26
-- =============================================================================
-- AÇÃO 1: Remover TODA a conferência (histórico de envelopes)
-- As FKs têm CASCADE ON DELETE:
--   cash_shifts → cash_closings → cash_closing_lines → divergences
-- Então basta deletar cash_shifts e tudo cascateia.
-- =============================================================================

-- Verificar contagem antes
SELECT 'ANTES DA LIMPEZA' as fase;
SELECT 'cash_shifts' as tabela, COUNT(*) as registros FROM cash_shifts
UNION ALL SELECT 'cash_closings', COUNT(*) FROM cash_closings
UNION ALL SELECT 'cash_closing_lines', COUNT(*) FROM cash_closing_lines
UNION ALL SELECT 'divergences', COUNT(*) FROM divergences;

-- Deletar toda conferência (cascata automática para closings, lines, divergences)
DELETE FROM cash_shifts;

SELECT 'CONFERENCIA REMOVIDA' as status;

-- =============================================================================
-- AÇÃO 2: Remover dados de PDV (turnos/closures) ANTES de 2026-02-19
-- =============================================================================

-- 2a) Remover pdv_closure_pagamentos dos closures antigos
DELETE pcp FROM pdv_closure_pagamentos pcp
INNER JOIN pdv_closures pc ON pcp.closure_uuid = pc.closure_uuid
WHERE pc.inicio_min < '2026-02-19';

-- 2b) Remover pdv_closures antigos
DELETE FROM pdv_closures WHERE inicio_min < '2026-02-19';

-- 2c) Identificar turnos antigos e deletar seus pagamentos
DELETE ptp FROM pdv_turno_pagamentos ptp
INNER JOIN pdv_turnos pt ON ptp.store_pdv_id = pt.store_pdv_id
    AND ptp.id_turno = pt.id_turno
    AND ptp.canal = pt.canal
WHERE pt.data_hora_inicio < '2026-02-19';

-- 2d) Remover turnos antigos
DELETE FROM pdv_turnos WHERE data_hora_inicio < '2026-02-19';

SELECT 'DADOS ANTIGOS PDV REMOVIDOS (antes 2026-02-19)' as status;

-- =============================================================================
-- AÇÃO 3: Limpar pdv_sync_payloads (payloads pesados)
-- =============================================================================

TRUNCATE TABLE pdv_sync_payloads;

SELECT 'pdv_sync_payloads TRUNCADO' as status;

-- =============================================================================
-- VERIFICAÇÃO FINAL
-- =============================================================================

SELECT 'DEPOIS DA LIMPEZA' as fase;
SELECT 'cash_shifts' as tabela, COUNT(*) as registros FROM cash_shifts
UNION ALL SELECT 'cash_closings', COUNT(*) FROM cash_closings
UNION ALL SELECT 'cash_closing_lines', COUNT(*) FROM cash_closing_lines
UNION ALL SELECT 'divergences', COUNT(*) FROM divergences
UNION ALL SELECT 'pdv_closures', COUNT(*) FROM pdv_closures
UNION ALL SELECT 'pdv_closure_pag', COUNT(*) FROM pdv_closure_pagamentos
UNION ALL SELECT 'pdv_turnos', COUNT(*) FROM pdv_turnos
UNION ALL SELECT 'pdv_turno_pag', COUNT(*) FROM pdv_turno_pagamentos
UNION ALL SELECT 'pdv_sync_payloads', COUNT(*) FROM pdv_sync_payloads;
