# Admin Store - Horários e Bio

Guia para o frontend administrar horários de atendimento e campo `bio_enabled` nas lojas.

---

## Campo `bio_enabled`

Este campo controla se a loja aparece na página pública da Bio do Instagram.

### API

```http
PUT /api/v1/admin/stores/{id}
Content-Type: application/json

{
  "bio_enabled": true
}
```

### Componente Sugerido: Switch Animado

```tsx
import { useState } from 'react';
import './Switch.css';

interface SwitchProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: string;
  disabled?: boolean;
}

export function Switch({ checked, onChange, label, disabled }: SwitchProps) {
  return (
    <label className={`switch-container ${disabled ? 'disabled' : ''}`}>
      {label && <span className="switch-label">{label}</span>}
      <div className="switch-wrapper">
        <input
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          disabled={disabled}
          className="switch-input"
        />
        <span className="switch-slider">
          <span className="switch-thumb" />
        </span>
      </div>
    </label>
  );
}
```

```css
/* Switch.css */
.switch-container {
  display: flex;
  align-items: center;
  gap: 12px;
  cursor: pointer;
}

.switch-container.disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.switch-label {
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
}

.switch-wrapper {
  position: relative;
  width: 48px;
  height: 26px;
}

.switch-input {
  opacity: 0;
  width: 0;
  height: 0;
  position: absolute;
}

.switch-slider {
  position: absolute;
  inset: 0;
  background: #d1d5db;
  border-radius: 26px;
  transition: background 0.3s ease;
}

.switch-input:checked + .switch-slider {
  background: linear-gradient(135deg, #10b981, #059669);
}

.switch-input:focus + .switch-slider {
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.3);
}

.switch-thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 20px;
  height: 20px;
  background: white;
  border-radius: 50%;
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.switch-input:checked + .switch-slider .switch-thumb {
  transform: translateX(22px);
}
```

### Uso no Form de Loja

```tsx
function StoreForm({ store, onSave }) {
  const [bioEnabled, setBioEnabled] = useState(store?.bio_enabled ?? false);
  const [saving, setSaving] = useState(false);

  const handleBioToggle = async (checked: boolean) => {
    setBioEnabled(checked);
    setSaving(true);
    
    try {
      await api.put(`/admin/stores/${store.id}`, { bio_enabled: checked });
      toast.success(checked ? 'Loja ativada na Bio!' : 'Loja removida da Bio');
    } catch (error) {
      setBioEnabled(!checked); // Rollback
      toast.error('Erro ao atualizar');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="form-group">
      <Switch
        checked={bioEnabled}
        onChange={handleBioToggle}
        label="Exibir na Bio do Instagram"
        disabled={saving}
      />
      <p className="form-hint">
        Quando ativado, a loja aparece na página pública da Bio com horários de funcionamento.
      </p>
    </div>
  );
}
```

---

## Horários de Atendimento (`opening_hours`)

### Estrutura Completa do JSON

```json
{
  "tz": "America/Sao_Paulo",
  "weekly": {
    "mon": [{ "start": "08:30", "end": "20:30" }],
    "tue": [{ "start": "08:30", "end": "20:30" }],
    "wed": [{ "start": "08:30", "end": "20:30" }],
    "thu": [{ "start": "08:30", "end": "20:30" }],
    "fri": [{ "start": "08:30", "end": "20:30" }],
    "sat": [{ "start": "09:00", "end": "18:00" }],
    "sun": []
  },
  "exceptions": [
    { "date": "2026-12-25", "closed": true, "reason": "Natal" },
    { "date": "2026-12-31", "hours": [{ "start": "08:00", "end": "14:00" }], "reason": "Véspera de Ano Novo" }
  ]
}
```

### Campos Obrigatórios

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `tz` | string | Timezone IANA (ex: `America/Sao_Paulo`) |
| `weekly` | object | Horários por dia da semana |
| `weekly.{day}` | array | Lista de intervalos (`[]` = fechado) |
| `weekly.{day}[].start` | string | Horário de abertura (`HH:MM`) |
| `weekly.{day}[].end` | string | Horário de fechamento (`HH:MM`) |

### Campos Opcionais

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `exceptions` | array | Exceções para datas específicas |
| `exceptions[].date` | string | Data no formato `YYYY-MM-DD` |
| `exceptions[].closed` | boolean | Se `true`, loja fechada neste dia |
| `exceptions[].hours` | array | Horários especiais (substitui weekly) |
| `exceptions[].reason` | string | Motivo (ex: "Feriado") |

### Dias da Semana

| Key | Dia |
|-----|-----|
| `mon` | Segunda-feira |
| `tue` | Terça-feira |
| `wed` | Quarta-feira |
| `thu` | Quinta-feira |
| `fri` | Sexta-feira |
| `sat` | Sábado |
| `sun` | Domingo |

### Exemplos de Cenários

#### Loja com 2 turnos (intervalo de almoço)

```json
{
  "tz": "America/Sao_Paulo",
  "weekly": {
    "mon": [
      { "start": "08:00", "end": "12:00" },
      { "start": "14:00", "end": "18:00" }
    ]
  }
}
```

#### Dia fechado

```json
{
  "sun": []
}
```

#### Feriado (exceção)

```json
{
  "exceptions": [
    { "date": "2026-12-25", "closed": true, "reason": "Natal" }
  ]
}
```

---

## Validar Horários (Preview)

Antes de salvar, use este endpoint para validar e previsualizar:

```http
POST /api/v1/admin/stores/validate-hours
Content-Type: application/json

{
  "opening_hours": {
    "tz": "America/Sao_Paulo",
    "weekly": { ... }
  }
}
```

### Resposta de Sucesso

```json
{
  "data": {
    "valid": true,
    "hours_human": {
      "is_open_now": true,
      "status_label": "Aberto agora • Fecha às 20:30",
      "today_hours_label": "Hoje: 08:30–20:30",
      "weekly_label": "Seg–Sáb 08:30–20:30 | Dom Fechado"
    }
  }
}
```

### Resposta de Erro

```json
{
  "message": "Validation failed.",
  "errors": {
    "opening_hours": [
      "O horário de início deve estar no formato HH:MM."
    ]
  }
}
```

---

## Componente Sugerido: Editor de Horários

```tsx
interface TimeSlot {
  start: string;
  end: string;
}

interface DaySchedule {
  day: string;
  label: string;
  slots: TimeSlot[];
  closed: boolean;
}

const DAYS = [
  { key: 'mon', label: 'Segunda' },
  { key: 'tue', label: 'Terça' },
  { key: 'wed', label: 'Quarta' },
  { key: 'thu', label: 'Quinta' },
  { key: 'fri', label: 'Sexta' },
  { key: 'sat', label: 'Sábado' },
  { key: 'sun', label: 'Domingo' },
];

function OpeningHoursEditor({ value, onChange }) {
  const handleDayChange = (dayKey: string, slots: TimeSlot[]) => {
    onChange({
      ...value,
      weekly: {
        ...value.weekly,
        [dayKey]: slots,
      },
    });
  };

  const handleToggleClosed = (dayKey: string, closed: boolean) => {
    handleDayChange(dayKey, closed ? [] : [{ start: '09:00', end: '18:00' }]);
  };

  return (
    <div className="hours-editor">
      <div className="timezone-select">
        <label>Timezone</label>
        <select
          value={value.tz}
          onChange={(e) => onChange({ ...value, tz: e.target.value })}
        >
          <option value="America/Sao_Paulo">São Paulo (BRT)</option>
          <option value="America/Manaus">Manaus (AMT)</option>
        </select>
      </div>

      {DAYS.map(({ key, label }) => {
        const slots = value.weekly?.[key] || [];
        const isClosed = slots.length === 0;

        return (
          <div key={key} className="day-row">
            <span className="day-label">{label}</span>
            
            <Switch
              checked={!isClosed}
              onChange={(open) => handleToggleClosed(key, !open)}
              label=""
            />

            {!isClosed && (
              <div className="slots">
                {slots.map((slot, idx) => (
                  <div key={idx} className="slot">
                    <input
                      type="time"
                      value={slot.start}
                      onChange={(e) => {
                        const newSlots = [...slots];
                        newSlots[idx].start = e.target.value;
                        handleDayChange(key, newSlots);
                      }}
                    />
                    <span>às</span>
                    <input
                      type="time"
                      value={slot.end}
                      onChange={(e) => {
                        const newSlots = [...slots];
                        newSlots[idx].end = e.target.value;
                        handleDayChange(key, newSlots);
                      }}
                    />
                    {slots.length > 1 && (
                      <button
                        type="button"
                        onClick={() => handleDayChange(key, slots.filter((_, i) => i !== idx))}
                      >
                        ✕
                      </button>
                    )}
                  </div>
                ))}
                <button
                  type="button"
                  className="add-slot"
                  onClick={() => handleDayChange(key, [...slots, { start: '14:00', end: '18:00' }])}
                >
                  + Adicionar intervalo
                </button>
              </div>
            )}

            {isClosed && <span className="closed-label">Fechado</span>}
          </div>
        );
      })}
    </div>
  );
}
```

---

## Checklist de Implementação

- [ ] Switch para `bio_enabled` com feedback visual
- [ ] Editor de horários por dia da semana
- [ ] Suporte a múltiplos intervalos por dia
- [ ] Toggle para dia fechado
- [ ] Preview com endpoint `/validate-hours`
- [ ] Exibir `hours_human.weekly_label` como resumo
- [ ] Gerenciar exceções (feriados)
