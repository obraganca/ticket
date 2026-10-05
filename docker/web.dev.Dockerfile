# Imagem de DESENVOLVIMENTO do frontend.
# Roda o servidor do Vite (com hot reload) em vez de gerar build estático.
# O código-fonte é montado via bind mount pelo docker-compose (ver volumes do
# serviço "web"), então só instalamos as dependências aqui — elas ficam
# guardadas na imagem e reaproveitadas via volume anônimo /app/node_modules,
# sem depender do node_modules da sua máquina (evita binário nativo de host
# diferente do Linux do container, ex: esbuild/rollup).

FROM node:20-alpine
WORKDIR /app

COPY web/package*.json ./
RUN npm ci

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
