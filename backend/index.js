const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
require('dotenv').config();

const app = express();
app.use(cors());
app.use(express.json());

// ================= CONFIGURAÇÃO DO BANCO =================

// Você pode usar variáveis de ambiente no Render,
// mas pra já funcionar, vou deixar direto aqui:
const DB_HOST = process.env.DB_HOST || 'trolley.proxy.rlwy.net';
const DB_PORT = process.env.DB_PORT || 29900;
const DB_USER = process.env.DB_USER || 'root';
const DB_PASS = process.env.DB_PASS || 'plfRtRyVMgYCBPMBDCBXgoNPofcwaEXj';
const DB_NAME = process.env.DB_NAME || 'railway';

// Pool de conexões
let pool;
async function getPool() {
  if (!pool) {
    pool = mysql.createPool({
      host: DB_HOST,
      port: DB_PORT,
      user: DB_USER,
      password: DB_PASS,
      database: DB_NAME,
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0
    });
  }
  return pool;
}

// ================= ROTAS DA API =================

// Teste de saúde / conexão
app.get('/api/test', async (req, res) => {
  try {
    const pool = await getPool();
    const [rows] = await pool.query('SELECT VERSION() AS version, DATABASE() AS db');
    res.json({
      success: true,
      message: 'API ok',
      mysql: rows[0]
    });
  } catch (err) {
    console.error(err);
    res.status(500).json({ success: false, error: 'Erro ao conectar ao MySQL', message: err.message });
  }
});

// Listar orçamentos
app.get('/api/orcamentos', async (req, res) => {
  try {
    const pool = await getPool();
    const [rows] = await pool.query(`
      SELECT 
        o.id,
        o.nome_cliente,
        o.data_criacao,
        o.data_atualizacao,
        o.total,
        COUNT(p.id) AS total_produtos
      FROM orcamentos o
      LEFT JOIN produtos_orcamento p ON o.id = p.orcamento_id
      GROUP BY o.id
      ORDER BY o.data_criacao DESC
    `);
    res.json({ success: true, data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ success: false, error: 'Erro ao listar orçamentos', message: err.message });
  }
});

// Obter orçamento específico
app.get('/api/orcamentos/:id', async (req, res) => {
  const id = parseInt(req.params.id);
  if (!id) return res.status(400).json({ success: false, error: 'ID inválido' });

  try {
    const pool = await getPool();
    const [[orc]] = await pool.query('SELECT * FROM orcamentos WHERE id = ?', [id]);

    if (!orc) {
      return res.status(404).json({ success: false, error: 'Orçamento não encontrado' });
    }

    const [produtos] = await pool.query(
      'SELECT * FROM produtos_orcamento WHERE orcamento_id = ? ORDER BY id ASC',
      [id]
    );

    orc.produtos = produtos;
    res.json({ success: true, data: orc });
  } catch (err) {
    console.error(err);
    res.status(500).json({ success: false, error: 'Erro ao obter orçamento', message: err.message });
  }
});

// Criar novo orçamento
app.post('/api/orcamentos', async (req, res) => {
  const { nomeCliente, total, produtos } = req.body;

  if (!nomeCliente || !Array.isArray(produtos) || produtos.length === 0 || total == null) {
    return res.status(400).json({ success: false, error: 'Dados incompletos para criar orçamento' });
  }

  const pool = await getPool();
  const conn = await pool.getConnection();
  await conn.beginTransaction();

  try {
    // Inserir orçamento
    const [result] = await conn.query(
      'INSERT INTO orcamentos (nome_cliente, total) VALUES (?, ?)',
      [nomeCliente, total]
    );
    const orcamentoId = result.insertId;

    // Inserir produtos
    const sqlProduto = `
      INSERT INTO produtos_orcamento (
        orcamento_id, nome_produto, quantidade, valor_filamento,
        material_gasto, tempo_impressao, taxa_energia, consumo_impressora,
        custo_energia_por_peca, custo_filamento_por_peca, custos_extras,
        extra_argola, extra_tag, preco_unitario, preco_total
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `;

    for (const p of produtos) {
      await conn.query(sqlProduto, [
        orcamentoId,
        p.nomeProduto,
        p.quantidade,
        p.valorFilamento,
        p.materialGasto,
        p.tempoImpressao,
        p.taxaEnergia,
        p.consumoImpressora,
        p.custoEnergiaPorPeca,
        p.custoFilamentoPorPeca,
        p.custosExtras,
        p.extraArgola ? 1 : 0,
        p.extraTag ? 1 : 0,
        p.precoUnitario,
        p.precoTotal
      ]);
    }

    await conn.commit();
    conn.release();

    res.json({ success: true, message: 'Orçamento criado', id: orcamentoId });
  } catch (err) {
    await conn.rollback();
    conn.release();
    console.error(err);
    res.status(500).json({ success: false, error: 'Erro ao criar orçamento', message: err.message });
  }
});

// Atualizar orçamento existente
app.put('/api/orcamentos/:id', async (req, res) => {
  const id = parseInt(req.params.id);
  const { nomeCliente, total, produtos } = req.body;

  if (!id || !nomeCliente || !Array.isArray(produtos)) {
    return res.status(400).json({ success: false, error: 'Dados incompletos para atualizar' });
  }

  const pool = await getPool();
  const conn = await pool.getConnection();
  await conn.beginTransaction();

  try {
    await conn.query(
      'UPDATE orcamentos SET nome_cliente = ?, total = ?, data_atualizacao = NOW() WHERE id = ?',
      [nomeCliente, total, id]
    );

    await conn.query('DELETE FROM produtos_orcamento WHERE orcamento_id = ?', [id]);

    const sqlProduto = `
      INSERT INTO produtos_orcamento (
        orcamento_id, nome_produto, quantidade, valor_filamento,
        material_gasto, tempo_impressao, taxa_energia, consumo_impressora,
        custo_energia_por_peca, custo_filamento_por_peca, custos_extras,
        extra_argola, extra_tag, preco_unitario, preco_total
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `;

    for (const p of produtos) {
      await conn.query(sqlProduto, [
        id,
        p.nomeProduto,
        p.quantidade,
        p.valorFilamento,
        p.materialGasto,
        p.tempoImpressao,
        p.taxaEnergia,
        p.consumoImpressora,
        p.custoEnergiaPorPeca,
        p.custoFilamentoPorPeca,
        p.custosExtras,
        p.extraArgola ? 1 : 0,
        p.extraTag ? 1 : 0,
        p.precoUnitario,
        p.precoTotal
      ]);
    }

    await conn.commit();
    conn.release();

    res.json({ success: true, message: 'Orçamento atualizado' });
  } catch (err) {
    await conn.rollback();
    conn.release();
    console.error(err);
    res.status(500).json({ success: false, error: 'Erro ao atualizar orçamento', message: err.message });
  }
});

// Deletar orçamento
app.delete('/api/orcamentos/:id', async (req, res) => {
  const id = parseInt(req.params.id);
  if (!id) return res.status(400).json({ success: false, error: 'ID inválido' });

  try {
    const pool = await getPool();
    const [result] = await pool.query('DELETE FROM orcamentos WHERE id = ?', [id]);

    if (result.affectedRows === 0) {
      return res.status(404).json({ success: false, error: 'Orçamento não encontrado' });
    }

    res.json({ success: true, message: 'Orçamento deletado' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ success: false, error: 'Erro ao deletar orçamento', message: err.message });
  }
});

// Porta (Render usa process.env.PORT)
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`🚀 API rodando na porta ${PORT}`);
});
