# Perfumería (PHP/MySQL en XAMPP)

## Regla permanente: reparto de trabajo por modelo
En TODA tarea de desarrollo de este proyecto, invoca primero la skill `model-router` y delega con subagentes:
- Simple/rápido → `worker-haiku`
- Normal → `worker-sonnet`
- Difícil o de alto riesgo (BD, seguridad, pagos, stock) → `worker-opus`

Tú orquestas y verificas; no hagas a mano lo que un subagente puede hacer. Responde siempre en español.
