const express = require('express');
const mongoose = require('mongoose');
const cors = require('cors');

const app = express();
app.use(cors());
app.use(express.json());

// SUBSTITUA PELA SUA CONNECTION STRING DO MONGODB ATLAS

const MONGO_URI = 'mongodb+srv://jaimern84:<db_password>@orcamento3d.wfcy6jk.mongodb.net/';

mongoose.connect(MONGO_URI, {
  useNewUrlParser: true,
  useUnifiedTopology: true
}).then(() => {
  console.log('✅ Conectado ao MongoDB Atlas');
}).catch(err => {
  console.error('❌ Erro ao conectar:', err);
});

// Schema do Orçamento
const orcamentoSchema = new mongoose.Schema({
  nomeCliente: String,
  data: { type: Date, default: Date.now },
  total: Number,
  produtos: [{
    nomeProduto: String,
    quantidade: Number,
    valorFilamento: Number,
    materialGasto: Number,
    tempoImpressao: Number,
    taxaEnergia: Number,
    consumoImpressora: Number,
    custoEnergiaPorPeca: Number,
    custoFilamentoPorPeca: Number,
    custosExtras: Number,
    extraArgola: Boolean,
    extraTag: Boolean,
    precoUnitario: Number,
    precoTotal: Number
  }]
});

const Orcamento = mongoose.model('Orcamento', orcamentoSchema);

// Rotas da API

// Listar todos os orçamentos
app.get('/api/orcamentos', async (req, res) => {
  try {
    const orcamentos = await Orcamento.find().sort({ data: -1 });
    res.json({ success: true, data: orcamentos });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Obter orçamento específico
app.get('/api/orcamentos/:id', async (req, res) => {
  try {
    const orcamento = await Orcamento.findById(req.params.id);
    if (!orcamento) {
      return res.status(404).json({ error: 'Orçamento não encontrado' });
    }
    res.json({ success: true, data: orcamento });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Criar novo orçamento
app.post('/api/orcamentos', async (req, res) => {
  try {
    const novoOrcamento = new Orcamento(req.body);
    await novoOrcamento.save();
    res.json({ success: true, data: novoOrcamento });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Atualizar orçamento
app.put('/api/orcamentos/:id', async (req, res) => {
  try {
    const orcamento = await Orcamento.findByIdAndUpdate(
      req.params.id,
      req.body,
      { new: true }
    );
    res.json({ success: true, data: orcamento });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Deletar orçamento
app.delete('/api/orcamentos/:id', async (req, res) => {
  try {
    await Orcamento.findByIdAndDelete(req.params.id);
    res.json({ success: true, message: 'Orçamento deletado' });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// Teste de conexão
app.get('/api/test', (req, res) => {
  res.json({ success: true, message: 'API funcionando!' });
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`🚀 Servidor rodando na porta ${PORT}`);
});
