FROM node:24-alpine
WORKDIR /app
ENV NODE_ENV=production
COPY package*.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY src ./src
COPY scripts ./scripts
COPY public ./public
RUN mkdir -p /app/data && chown -R node:node /app
USER node
EXPOSE 8787
CMD ["npm", "start"]
