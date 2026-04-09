<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PetCRM - Gestão Completa para Clínicas Veterinárias</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        pet: {
                            dark: '#0a3b36',
                            light: '#189e78',
                            bg: '#e6f4f1',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        .paw-print {
            position: fixed;
            color: #189e78;
            opacity: 0;
            pointer-events: none;
            transition: opacity 1.5s ease-in-out;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden relative">

    <div id="paw-container" class="fixed inset-0 overflow-hidden pointer-events-none z-0"></div>

    <nav class="container mx-auto px-6 py-6 flex justify-between items-center bg-white/95 backdrop-blur-sm sticky top-0 z-50 shadow-sm">
        <div class="flex items-center space-x-2 text-2xl font-extrabold text-pet-dark">
            <svg class="w-8 h-8 text-pet-light" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-5 3c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-5 6c-3.87 0-7 3.13-7 7 0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2 0-3.87-3.13-7-7-7z"/></svg>
            <span>PetCRM</span>
        </div>
        
        <div class="flex items-center space-x-6 relative z-20">
            <a href="sys/login.php" class="text-pet-dark font-bold hover:text-pet-light transition-colors duration-300">Login</a>
            
            <a href="https://wa.me/554198564337?text=Ol%C3%A1!%20Gostaria%20de%20solicitar%20uma%20demonstra%C3%A7%C3%A3o%20do%20PetCRM." target="_blank" class="bg-pet-light text-white px-6 py-2.5 rounded-full font-bold hover:bg-pet-dark transition-colors duration-300 shadow-md">Solicite seu orçamento</a>
        </div>
    </nav>

    <section class="bg-pet-bg py-16 lg:py-24 relative z-10 border-b border-gray-200">
        <div class="container mx-auto px-6 text-center">
            <h1 class="text-4xl lg:text-5xl font-extrabold text-pet-dark mb-6 leading-tight">
                Sua Clínica Veterinária Sob Controle:<br>
                Gestão Completa e Simplificada.
            </h1>
            <p class="text-xl text-pet-dark mb-10 max-w-2xl mx-auto font-medium">
                Faturamento, Agendamentos e Clientes em um só lugar.<br>
                Eficiência e Cuidado do cliente.
            </p>

            <div class="relative max-w-4xl mx-auto bg-white rounded-2xl shadow-2xl overflow-hidden border-4 border-white">
                <video class="w-full h-auto aspect-video object-cover bg-gray-900" controls preload="metadata" poster="img/templeat.jfif">
                    <source src="./crmpet.mp4" type="video/mp4">
                    Seu navegador não suporta a tag de vídeo.
                </video>
            </div>
        </div>
    </section>

    <section class="container mx-auto px-6 py-20 space-y-32 relative z-10">
        <div class="flex flex-col lg:flex-row items-center gap-12">
            <div class="lg:w-1/2">
                <img src="img/gestaoinventario.jpeg" alt="Controle de Estoque Veterinário" class="rounded-2xl shadow-xl w-full">
            </div>
            <div class="lg:w-1/2 space-y-6 bg-white p-8 rounded-2xl shadow-lg border border-gray-100">
                <h2 class="text-3xl lg:text-4xl font-extrabold text-pet-dark leading-tight">Controle Total do seu Estoque Veterinário: Gestão Simplificada e Precisa de Produtos.</h2>
                <p class="text-lg text-gray-700">Acompanhe validades, quantidades e marcas em um só lugar.</p>
                <div class="space-y-4 pt-4">
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Gestão CRUD Completa:</strong> Adicione, edite e remova produtos facilmente.</p>
                    </div>
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Evite Perdas e Vencimentos:</strong> Alertas de estoque e datas.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row-reverse items-center gap-12">
            <div class="lg:w-1/2">
                <img src="img/gestaousuarios.jpeg" alt="Gerenciamento de Equipe" class="rounded-2xl shadow-xl w-full">
            </div>
            <div class="lg:w-1/2 space-y-6 bg-white p-8 rounded-2xl shadow-lg border border-gray-100">
                <h2 class="text-3xl lg:text-4xl font-extrabold text-pet-dark leading-tight">Controle Total da Sua Equipe e Permissões: Sistema de Gerenciamento de Usuários Flexível.</h2>
                <p class="text-lg text-gray-700">Defina funções (Gerente, Veterinário, Atendente), personalize acessos e garanta a segurança dos dados do seu Petshop.</p>
                <div class="space-y-4 pt-4">
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Acesso por Função:</strong> Cada usuário com permissões específicas.</p>
                    </div>
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Controle Flexível:</strong> Edite acessos e funções a qualquer momento.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row items-center gap-12">
            <div class="lg:w-1/2">
                <img src="img/seguranca.jpeg" alt="Tecnologia Blindada" class="rounded-2xl shadow-xl w-full">
            </div>
            <div class="lg:w-1/2 space-y-6 bg-white p-8 rounded-2xl shadow-lg border border-gray-100">
                <h2 class="text-3xl lg:text-4xl font-extrabold text-pet-dark leading-tight">Tecnologia Blindada: Seus Dados Protegidos</h2>
                <p class="text-lg text-gray-700">Adotamos os padrões mais modernos da indústria web para proteger o acesso ao seu sistema.</p>
                <div class="space-y-4 pt-4">
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Criptografia de Via Única (Hashing):</strong> As senhas são convertidas em códigos irreversíveis.</p>
                    </div>
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Proteção Individualizada:</strong> Cada usuário possui uma chave de segurança única.</p>
                    </div>
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-pet-light mr-3 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <p class="text-gray-700"><strong class="text-pet-dark">Anti-Força Bruta:</strong> Algoritmos inteligentes que bloqueiam tentativas de invasão automatizadas.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="bg-pet-dark text-white py-16 border-t-8 border-pet-light relative z-20">
        <div class="container mx-auto px-6 text-center">
            <h2 class="text-3xl font-extrabold mb-6">Pronto para ter seu PetShop sob controle?</h2>
            <a href="https://wa.me/554198564337?text=Ol%C3%A1!%20Estou%20no%20site%20e%20quero%20come%C3%A7ar%20a%20usar%20o%20PetCRM." target="_blank" class="inline-block bg-pet-light text-white px-10 py-4 rounded-full font-bold text-lg hover:bg-white hover:text-pet-dark transition-colors duration-300 shadow-lg">Começar Agora com PetCRM</a>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('paw-container');
            
            const pawSvg = `<svg width="100%" height="100%" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-5 3c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-5 6c-3.87 0-7 3.13-7 7 0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2 0-3.87-3.13-7-7-7z"/></svg>`;

            function spawnWalkingTrail() {
                const startX = 10 + Math.random() * 80; 
                const startY = 95; 
                
                const steps = 10 + Math.floor(Math.random() * 6); 
                const angle = (Math.random() * 30) - 15; 
                
                let currentX = startX;
                let currentY = startY;

                for (let i = 0; i < steps; i++) {
                    setTimeout(() => {
                        const paw = document.createElement('div');
                        paw.className = 'paw-print';
                        
                        const isRightPaw = i % 2 === 0;
                        const offsetX = isRightPaw ? 4 : -4; 
                        
                        currentY -= 10; 
                        currentX += offsetX + (angle * 0.1); 

                        paw.style.left = `${currentX}vw`;
                        paw.style.top = `${currentY}vh`;
                        paw.style.width = '40px'; 
                        paw.style.height = '40px';
                        
                        const rotation = isRightPaw ? 15 + angle : -15 + angle;
                        paw.style.transform = `rotate(${rotation}deg)`;
                        paw.innerHTML = pawSvg;

                        container.appendChild(paw);

                        requestAnimationFrame(() => {
                            paw.style.opacity = '0.25'; 
                            setTimeout(() => {
                                paw.style.opacity = '0'; 
                                setTimeout(() => paw.remove(), 1500); 
                            }, 3500); 
                        });
                        
                    }, i * 450); 
                }
            }

            spawnWalkingTrail();
            setInterval(spawnWalkingTrail, 3500); 
        });
    </script>
</body>
</html>