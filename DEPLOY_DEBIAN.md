# 🚀 Guia de Deploy no Servidor Debian com Docker

Este guia contém o passo a passo exato para subir o **PetCRM** em um servidor **Debian 11 ou 12** utilizando **Docker** e **Docker Compose**.

---

## 📋 Pré-requisitos
* Um servidor ou VPS rodando **Debian** com acesso `root` ou usuário com privilégios `sudo`.
* Portas `80` (HTTP), `443` (HTTPS - opcional) e `8080` (phpMyAdmin - opcional) liberadas no firewall.

---

## Passo 1: Instalar Docker e Docker Compose no Debian

Acesse o terminal do seu servidor Debian e execute o instalador oficial do Docker:

```bash
# Atualiza os pacotes do sistema
sudo apt update && sudo apt upgrade -y

# Instala curl se ainda não tiver
sudo apt install -y curl git ufw

# Instala o Docker oficial com um único comando
curl -fsSL https://get.docker.com | sh

# Habilita o Docker para iniciar junto com o sistema
sudo systemctl enable --now docker

# Adiciona seu usuário ao grupo do Docker (para rodar sem sudo)
sudo usermod -aG docker $USER
newgrp docker
```

Verifique a instalação:
```bash
docker --version
docker compose version
```

---

## Passo 2: Copiar os Arquivos do Projeto para o Servidor

Você pode enviar os arquivos via `git clone` ou via `rsync` / `scp` a partir da sua máquina local:

### Opção A: Clonando via Git no Servidor
```bash
cd /var/www
# ou na sua pasta home:
cd ~
git clone <URL_DO_SEU_REPOSITORIO> petcrm
cd petcrm
```

### Opção B: Copiando do Windows via SCP / Rsync (Exemplo PowerShell)
```powershell
# No PowerShell do seu computador local:
scp -r C:\xampp\htdocs\landing_page usuario@seu-ip-debian:~/petcrm
```

---

## Passo 3: Iniciar o Sistema com Docker

Dentro da pasta do projeto no servidor Debian:

```bash
cd ~/petcrm

# Constrói a imagem e sobe todos os contêineres em segundo plano
docker compose up -d --build
```

O Docker fará o seguinte automaticamente:
1. Constrói a imagem PHP 8.2 com Apache e todas as extensões necessárias (`pdo_mysql`, `gd`, `zip`, etc.).
2. Inicia o banco de dados MariaDB.
3. **Importa automaticamente o arquivo `docker/init.sql`** contendo todas as tabelas, instituições (incluindo VittaPet), usuários, clientes, produtos, agendamentos e vendas.
4. Conecta o PHP ao banco de dados através das variáveis de ambiente.

Para verificar se os contêineres estão rodando:
```bash
docker compose ps
```

Você verá:
* `petcrm_web` -> Rodando na porta 80
* `petcrm_db` -> Rodando internamente e conectado ao web
* `petcrm_pma` -> Rodando na porta 8080 (phpMyAdmin)

---

## Passo 4: Acessar a Aplicação

Abra o seu navegador:
* **Landing Page:** `http://IP_DO_SEU_SERVIDOR/`
* **Painel do PetCRM:** `http://IP_DO_SEU_SERVIDOR/sys/login.php`
* **phpMyAdmin (Opcional):** `http://IP_DO_SEU_SERVIDOR:8080/`
  * Usuário: `root`
  * Senha: `root_super_senha_segura`

### Credenciais Criadas para Teste Imediato:
* **Gerente Geral (VittaPet):**
  * E-mail: `gerente.vittapet@petcrm.com.br`
  * Senha: `vittapet@123`

---

## 🛠️ Comandos Úteis no Debian

```bash
# Ver os logs do Apache/PHP em tempo real
docker compose logs -f web

# Ver os logs do banco de dados
docker compose logs -f db

# Reiniciar todos os serviços
docker compose restart

# Parar os serviços
docker compose down

# Parar e resetar o banco de dados do zero (se quiser reimportar o init.sql)
docker compose down -v
docker compose up -d
```

---

## 🔒 Passo 5 (Recomendado): Colocar SSL Grátis (HTTPS) com Nginx Reverse Proxy & Certbot

Se você tiver um domínio apontado para o seu servidor (ex: `app.meupetshop.com.br`):

1. Mude a porta do serviço `web` no `docker-compose.yml` de `"80:80"` para `"8000:80"`.
2. Reinicie os contêineres: `docker compose up -d`.
3. Instale o Nginx e Certbot no Debian:
```bash
sudo apt install -y nginx certbot python3-certbot-nginx
```
4. Crie a configuração em `/etc/nginx/sites-available/petcrm`:
```nginx
server {
    server_name app.meupetshop.com.br;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```
5. Ative o site e gere o certificado SSL grátis:
```bash
sudo ln -s /etc/nginx/sites-available/petcrm /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.meupetshop.com.br
```
Pronto! Seu PetCRM estará rodando com HTTPS profissional e certificado renovado automaticamente.

