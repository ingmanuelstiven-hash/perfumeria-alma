---
name: model-router
description: Reparte el trabajo entre subagentes según dificultad — Haiku para lo simple/rápido, Sonnet para lo normal, Opus para lo difícil. Úsala SIEMPRE al desarrollar en este proyecto (perfumería, PHP/MySQL en XAMPP) cada vez que haya que implementar, corregir, buscar, revisar o diseñar algo.
---

# Model Router — delegación por dificultad

Tú (el agente principal) eres el **orquestador**: planificas, repartes y verificas. No hagas tú mismo el trabajo que un subagente más barato puede hacer. Delega con la herramienta `Agent`, usando `subagent_type`:

| Nivel | subagent_type | Modelo | Cuándo |
|---|---|---|---|
| Simple / rápido | `worker-haiku` | Haiku | Buscar/leer archivos, renombrar, formatear, copys y descripciones de perfumes, datos de ejemplo (seeds), cambios triviales de 1 archivo, correr comandos de verificación |
| Normal | `worker-sonnet` | Sonnet | CRUD de productos/clientes/pedidos, formularios, vistas, endpoints, consultas SQL comunes, tests, bugs con causa conocida, refactors moderados, documentación |
| Difícil | `worker-opus` | Opus | Esquema de BD y arquitectura, login/sesiones/roles, pagos y facturación, carrito/stock/precios (concurrencia, transacciones), SQLi/XSS/CSRF, bugs complejos o intermitentes, revisión de código, migraciones |

## Procedimiento

1. **Descompón** la petición en tareas pequeñas e independientes.
2. **Clasifica** cada tarea con el árbol de decisión:
   - ¿Toca dinero, seguridad, integridad de datos o arquitectura? → **Opus**
   - ¿Es mecánica, de bajo riesgo y de 1 archivo/comando? → **Haiku**
   - Todo lo demás → **Sonnet**
   - En caso de duda entre dos niveles, elige el **superior** si hay riesgo, el inferior si no.
3. **Paraleliza**: lanza en un mismo mensaje todas las tareas independientes.
4. **Brief completo**: cada subagente empieza sin contexto. Incluye archivos/rutas, qué debe hacer, criterio de "terminado" y restricciones (ver contexto abajo).
5. **Escalado**: si un subagente responde `ESCALAR: ...`, reasigna al nivel superior con ese motivo. No reintentes en el mismo nivel.
6. **Verifica** el resultado (lee el diff o ejecuta la prueba) antes de dar la tarea por buena. Un trabajo de Opus puede delegar la revisión final a sí mismo; el de Haiku siempre se revisa.
7. **Reporta** al usuario un resumen breve: qué se hizo y qué modelo lo hizo.

## No delegues
- Preguntas rápidas o ediciones de 1–2 líneas que ya tienes a la vista: hazlas tú.
- Conversación y decisiones con el usuario.

## Contexto del proyecto (pásalo en los briefs)
- Tienda de perfumería. Stack asumido: **PHP + MySQL/MariaDB sobre XAMPP**, ruta `C:\xampp\perfumeria` (Windows, servida por Apache en `htdocs` o vhost).
- Entidades típicas: productos (marca, notas olfativas, tamaño ml, precio, stock), categorías, clientes, pedidos, detalle de pedido, usuarios/roles.
- Reglas fijas: sentencias preparadas siempre; `password_hash`/`password_verify`; escapar salida con `htmlspecialchars`; precios en `DECIMAL(10,2)`; operaciones de stock y pedidos dentro de transacciones; UTF-8 (`utf8mb4`) en BD y HTML.
- Idioma de UI y comentarios: español.
- Si el stack real difiere (Laravel, Node, etc.), adáptate al código existente y actualiza esta sección.

## Ahorro
Haiku ≈ barato/rápido, Sonnet ≈ equilibrio, Opus ≈ costoso. Usa Opus solo donde un error sale caro. La mayoría del trabajo (≈70%) debe ir a Sonnet/Haiku.
