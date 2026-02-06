# SLA Flow – Relatório de SLA via SQL

O **SLA Flow** é uma aplicação web frontend desenvolvida em HTML, CSS (Tailwind) e JavaScript, utilizada para consultar, processar e visualizar relatórios de SLA a partir de um banco de dados SQL (ex: Zabbix), com foco em indicadores de indisponibilidade e conformidade de SLA.

## 📌 Visão Geral

A interface permite:
- Conexão dinâmica a um banco de dados SQL
- Filtros por período, grupo e host
- Visualização em **tabela** e **dashboard**
- Exportação dos dados para **Excel**
- Persistência de filtros via `localStorage`

O processamento dos dados é realizado por um backend externo (`process_query.php`), que retorna os dados já tratados em formato JSON.

---

## 🧱 Estrutura do Arquivo

### `index.html`
Arquivo único responsável por toda a interface visual e lógica frontend da aplicação.

Contém:
- Layout (HTML)
- Estilização (Tailwind CSS + CSS customizado)
- Lógica de interação, requisições e gráficos (JavaScript)

---

## 🎨 Tecnologias Utilizadas

- **HTML5**
- **Tailwind CSS (CDN)**
- **Chart.js** – gráficos (barra e doughnut)
- **SheetJS (xlsx)** – exportação para Excel
- **Font Awesome** – ícones
- **Google Fonts** – Raleway e Outfit
- **JavaScript puro (Vanilla JS)**

---

## 🖥️ Funcionalidades

### 🔹 Consulta de SLA
- Conexão com banco SQL via backend
- Envio de parâmetros por `fetch` (POST em JSON)
- Campos:
  - IP do banco
  - Nome do banco
  - Usuário
  - Senha
  - Data inicial e final
  - Grupo
  - Host

### 🔹 Visualização em Tabela
- Host
- Gatilho
- Percentual de incidentes
- Percentual de disponibilidade (OK)

### 🔹 Dashboard
- **Gráfico de barras**: Top 10 hosts com maior downtime
- **Gráfico de rosca**: Distribuição de SLA OK vs Crítico

### 🔹 Exportação
- Exporta os dados consultados para `SLA_Valgroup.xlsx`

### 🔹 Persistência de Dados
- Campos de filtro são salvos no `localStorage`
- Recarregados automaticamente ao abrir a página

---

## 🔄 Fluxo de Funcionamento

1. Usuário preenche os filtros
2. Clica em **Consultar**
3. Frontend envia requisição para `process_query.php`
4. Backend retorna JSON com os dados processados
5. Frontend:
   - Preenche a tabela
   - Gera os gráficos
   - Libera o botão de exportação

---

## ⚙️ Backend Esperado

O arquivo `process_query.php` deve:
- Receber JSON via POST
- Conectar ao banco de dados
- Executar a query de SLA
- Retornar um array JSON no formato:

```json
[
  {
    "Host": "HOST01",
    "Nome": "Trigger exemplo",
    "Incidentes": "2.35",
    "Ok": "97.65"
  }
]
```

## Dashboard

<img width="1902" height="893" alt="image" src="https://github.com/user-attachments/assets/6c1a5575-9cb2-42be-8635-6a0c06e0698e" />

