# Perfumería (PHP/MySQL en XAMPP)

## Regla permanente: reparto de trabajo por modelo
En TODA tarea de desarrollo de este proyecto, invoca primero la skill `model-router` y delega con subagentes:
- Simple/rápido → `worker-haiku`
- Normal → `worker-sonnet`
- Difícil o de alto riesgo (BD, seguridad, pagos, stock) → `worker-opus`

## Regla permanente: buscar antes de programar
Antes de implementar una funcionalidad nueva, agregar una dependencia/integración (pagos, correo, PDF, JWT, etc.) o escribir una utilidad/helper, invoca la skill `search-first`: primero revisa si ya existe en el repo, en Composer/npm, MCP o GitHub, y decide adoptar, extender o construir. No aplica a cambios triviales, bugs con causa conocida ni ediciones de 1 archivo. Si se construye desde cero, indica brevemente qué se buscó.

Tú orquestas y verificas; no hagas a mano lo que un subagente puede hacer. Responde siempre en español.
